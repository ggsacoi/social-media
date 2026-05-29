<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once 'config.php';
if(!isset($_SESSION['email'])) {
    header("Location: index.php");
    exit();
}
$email = $_SESSION['email'];
$stmt = $conn->prepare('SELECT id, username FROM users WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
if($result->num_rows === 0) {
    header("Location: index.php");
    exit();
}
$user = $result->fetch_assoc();
$userId = $user['id'];
$userName = $user['username'];
$profilePic = $_SESSION['profile_pic'] ?? ''; // Récupérer la photo de profil de la session
// Initialisation d'une graine (seed) pour la randomisation stable des commentaires (évite les doublons en pagination)
// On la régénère à chaque chargement de page pour que l'ordre soit différent à chaque visite
if (!isset($_GET['ajax']) && !isset($_GET['ajax_comments']) && !isset($_GET['ajax_users'])) {
    $_SESSION['comment_seed'] = rand(1, 999999);
}
$commentSeed = (int)$_SESSION['comment_seed'];
$error_msg = '';
// Inclure les handlers
require_once 'post_handler.php';
require_once 'message_handler.php';
require_once 'ajax_handlers.php';
require_once 'data_loader.php';
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>lemonde</title>
        <link rel="stylesheet" href="home.css?v=12">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
        <!-- Google AdSense : chargement asynchrone de la librairie publicitaire -->
        <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js"></script>
        <style>
            #nsfwStatus {
                position: fixed;
                top: 70px;
                right: 20px;
                padding: 12px 16px;
                border-radius: 6px;
                font-size: 0.9rem;
                z-index: 999;
                max-width: 300px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.15);
                background: #f0f0f0;
                display: none;
            }
            #nsfwStatus.show {
                display: block;
            }
            .adsense-banner {
                width: 100%;
                max-width: 728px;
                margin: 12px auto 20px;
                text-align: center;
            }
            .adsense-banner.post-ad {
                margin: 24px auto;
            }
            .active-live-list {
                margin: 16px 0;
                padding: 12px;
                border: 1px solid rgba(255, 0, 0, 0.25);
                border-radius: 12px;
                background: rgba(255, 0, 0, 0.05);
            }
            .active-live-list h3 {
                margin: 0 0 10px;
                font-size: 1rem;
                color: #e53935;
            }
            .active-live-item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
                padding: 10px 0;
                border-bottom: 1px solid rgba(0,0,0,0.08);
            }
            .active-live-item:last-child {
                border-bottom: none;
            }
            .active-live-item .live-user {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .active-live-item .live-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 999px;
                background: #e53935;
                color: white;
                font-size: 0.75rem;
                font-weight: 700;
                text-transform: uppercase;
            }
            .active-live-item .watch-btn {
                background: #e53935;
                color: white;
                padding: 6px 10px;
                border-radius: 8px;
                text-decoration: none;
                font-size: 0.9rem;
            }
            .active-live-empty {
                color: #555;
                font-size: 0.95rem;
            }
        </style>
        <script>
            // DEBUG: Vérifier le token
            <?php 
                $token = getCsrfToken();
                error_log('=== user_page.php DEBUG ===');
                error_log('getCsrfToken() returned: ' . ($token ? 'OK (' . strlen($token) . ' chars)' : 'EMPTY'));
                error_log('$_SESSION csrf_token exists: ' . (isset($_SESSION['csrf_token']) ? 'YES' : 'NO'));
            ?>
            window.csrfToken = "<?php echo htmlspecialchars(getCsrfToken()); ?>";
            window.currentUserId = <?php echo json_encode($userId); ?>;
            window.currentUserName = <?php echo json_encode($userName); ?>;
            console.log('[PAGE LOAD] window.csrfToken:', window.csrfToken.substring(0, 20) + (window.csrfToken.length > 20 ? '...' : ''));
        </script>
    </head>
    <body>
        <nav class="navi">
                <div class="search-box">
                    <h1 onclick="window.location.href='user_page.php'" style="text-decoration:none; font-size:40px; font-weight:bold; color: red; cursor:pointer;" class="logo-link">Mervie</h1>
                </div>
                <div id="nsfwStatus"></div>
                <form method="POST" action="user_page.php" enctype="multipart/form-data" class="sendPost">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">
                    <input type="hidden" name="submit_post" value="1">
                    <input type="text" name="legende" id="post_contenu" placeholder="Quoi de neuf ?">
                    <button type="submit" name="submit_post" class="btn-send">Poster</button>
                    <button type="button" class="sendMedia" onclick="openMediaFile('post')"><i class="fa-regular fa-image"></i></button>
                    <button type="button" id="post_micButton"><i class="fa-solid fa-microphone"></i></button>
                    <input type="file" name="media_file" id="post_mediaInput" style="display:none;" accept="image/*,video/*,audio/*">
                    <input type="hidden" id="post_recordedAudioData" name="recorded_audio_data">
                    <button type="button" style="background: red;" id="liveStreamButton"><i class="fa-solid fa-tower-broadcast" style="color: white;"></i></button>
                    <div id="post_fileNameDisplay" style="display:none;"></div>
                </form>
                <span id="post_recordStatus"></span>
                <!-- <div class="adsense-banner">
                    Bloc publicitaire AdSense : remplacez ca-pub et data-ad-slot par vos propres identifiants
                    <ins class="adsbygoogle"
                         style="display:inline-block;width:320px;height:100px"
                         data-ad-client="ca-pub-XXXXXXXXXXXXXXXX"
                         data-ad-slot="1234567890"></ins>
                    <script>
                        (adsbygoogle = window.adsbygoogle || []).push({});
                    </script>
                </div> -->
                <div class="rightside">
                    <div class="user-mini">
                        <span><?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="home" style="<?php echo !empty($profilePic) ? 'background-image: url(\'' . htmlspecialchars($profilePic, ENT_QUOTES, 'UTF-8') . '\');' : ''; ?>">
                            <?php if (empty($profilePic)): ?>
                                <i class="fa-solid fa-user"></i>
                            <?php endif; ?>
                        </span>
                        <div class="user-dropdown">
                            <a href="profil.php"><i class="fa-solid fa-user"></i> Profil</a>
                            <a href="user_page.php"><i class="fa-solid fa-house"></i> Accueil</a>
                            <a href="logout.php" style="color: #ff4d4d;"><i class="fa-solid fa-right-from-bracket"></i> Déconnexion</a>
                        </div>
                    </div>
                </div>
        </nav>
        <main class="ndaku">
        <article class="bomoto">
                    <div id="users-list">
                        <?php while($sideUser = $sidebarUsers->fetch_assoc()): ?>
                            <div class="eloko" data-user-id="<?php echo htmlspecialchars($sideUser['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                <a href="profil.php?u=<?php echo urlencode($sideUser['username']); ?>" class="avatar-link">
                                    <span class="people" style="<?php echo !empty($sideUser['profile_pic']) ? "background-image: url('" . htmlspecialchars($sideUser['profile_pic']) . "'); background-size: cover;" : ''; ?>">
                                        <?php if(empty($sideUser['profile_pic'])): ?><i class="fa-solid fa-user"></i><?php endif; ?>
                                    </span>
                                </a>
                                <a href="profil.php?u=<?php echo urlencode($sideUser['username']); ?>" class="name-link">
                                    <h4><?php echo htmlspecialchars($sideUser['username']); ?></h4>
                                </a>
                                <?php if ($sideUser['id'] == $userId):
                                    // Bouton pour l'utilisateur courant — id conservé pour les scripts JS existants
                                    $ownLive = !empty($sideUser['is_live']);
                                ?>
                                    <button type="button" id="liveStreamButton" style="background: <?php echo $ownLive ? '#4caf50' : 'red'; ?>; color: white; padding:6px 8px; border-radius:6px;">
                                        <?php echo $ownLive ? 'En direct' : 'Démarrer live'; ?>
                                    </button>
                                <?php else: ?>
                                    <?php if (!empty($sideUser['is_live'])): ?>
                                        <a href="view_livestream.php?u=<?php echo urlencode($sideUser['username']); ?>" class="view-live-btn" style="background:#e53935;color:white;padding:6px 8px;border-radius:6px;text-decoration:none;">Voir le live</a>
                                    <?php else: ?>
                                        <span style="color:#666;font-size:0.9rem;">&nbsp;</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <a href="user_page.php?with=<?php echo urlencode($sideUser['username']); ?>" class="chat-icon-btn" title="Envoyer un message">
                                    <i class="fa-regular fa-comment-dots"></i>
                                </a>
                            </div>
                        <?php endwhile; ?>
                    </div>
                    <div id="active-live-list" class="active-live-list">
                        <h3>Lives en cours</h3>
                        <div id="activeLivesContainer">
                            <p class="active-live-empty">Chargement des lives en cours...</p>
                        </div>
                    </div>
                    <?php if ($totalUserPages > 1): ?>
                        <button id="load-more-users" data-total-pages="<?php echo $totalUserPages; ?>">Afficher plus</button>
                    <?php endif; ?>
                </article>
        <section class="news">
                <div class="content">
                    <?php $postIndex = 0; ?>
                    <?php while($post = $allPosts->fetch_assoc()): ?>
                        <div class="post-item" id="post-<?php echo $post['id']; ?>">
                            <div class="top">
                                <a href="profil.php?u=<?php echo urlencode($post['username']); ?>" class="character" style="text-decoration:none; color:inherit; display:flex; align-items:center; gap:10px;">
                                    <span class="people" style="background-image: url('<?php echo htmlspecialchars($post['profile_pic']); ?>'); background-size: cover;">
                                        <?php if(empty($post['profile_pic'])): ?><i class="fa-solid fa-user"></i><?php endif; ?>
                                    </span> 
                                    <h3><?php echo htmlspecialchars($post['username']); ?></h3>
                                </a>
                                <h3 style="display: flex; align-items: center; justify-content: center;" ><?php echo htmlspecialchars($post['legende']); ?></h3>
                            </div>
                            <?php if($post['media_url']): ?>
                                <?php if($post['media_type'] === 'image'): ?>
                                    <img class="subject" src="<?php echo htmlspecialchars($post['media_url']); ?>" alt="Post Image">
                                <?php elseif($post['media_type'] === 'video'): ?>
                                    <video controls class="subject" style="max-width: 100%;"><source src="<?php echo htmlspecialchars($post['media_url']); ?>"></video>
                                <?php elseif($post['media_type'] === 'audio'): ?>
                                    <div class="audio-visualizer" data-src="<?php echo htmlspecialchars($post['media_url']); ?>">
                                        <button type="button" class="audio-play-button">▶ Play</button>
                                        <canvas></canvas>
                                        <span class="audio-status">Audio</span>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <!-- Display Comments -->
                            <div class="comments-section" id="comments-section-<?php echo $post['id']; ?>">
                                <?php
                                $countStmt = $conn->prepare('SELECT COUNT(*) as total FROM comments WHERE post_id = ?');
                                $countStmt->bind_param('i', $post['id']);
                                $countStmt->execute();
                                $totalComments = $countStmt->get_result()->fetch_assoc()['total'];
                                $totalCommentPages = ($totalComments > 1) ? ceil(($totalComments - 1) / 5) + 1 : 1;
                                $stmtComments = $conn->prepare("SELECT c.*, u.username, u.profile_pic FROM comments c JOIN users u ON c.user_id = u.id WHERE c.post_id = ? ORDER BY RAND($commentSeed + c.id) LIMIT 1");
                                $stmtComments->bind_param('i', $post['id']);
                                $stmtComments->execute();
                                $postComments = $stmtComments->get_result();
                                while($comment = $postComments->fetch_assoc()):
                                ?>
                                    <div class="comment-item" data-comment-username="<?php echo htmlspecialchars($comment['username'], ENT_QUOTES, 'UTF-8'); ?>" data-comment-content="<?php echo htmlspecialchars($comment['contenu'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <div class="comment-character">
                                            <span class="people" style="background-image: url('<?php echo htmlspecialchars($comment['profile_pic']); ?>'); background-size: cover;">
                                                <?php if(empty($comment['profile_pic'])): ?><i class="fa-solid fa-user"></i><?php endif; ?>
                                            </span>
                                            <h4><?php echo htmlspecialchars($comment['username']); ?></h4>
                                        </div>
                                        <p><?php echo htmlspecialchars($comment['contenu']); ?></p>
                                        <?php if($comment['media_url']): ?>
                                            <?php if($comment['media_type'] === 'image'): ?>
                                                <img class="comment-subject" src="<?php echo htmlspecialchars($comment['media_url']); ?>" alt="Comment Image">
                                            <?php elseif($comment['media_type'] === 'video'): ?>
                                                <video controls class="comment-subject" style="max-width: 100%;"><source src="<?php echo htmlspecialchars($comment['media_url']); ?>"></video>
                                            <?php elseif($comment['media_type'] === 'audio'): ?>
                                                <div class="audio-visualizer" data-src="<?php echo htmlspecialchars($comment['media_url']); ?>">
                                                    <button type="button" class="audio-play-button">▶ Play</button>
                                                    <canvas></canvas>
                                                    <span class="audio-status">Audio</span>
                                                    <u
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endwhile;
                                $stmtComments->close();
                                ?>
                            </div>
                            <?php if ($totalCommentPages > 1): ?>
                                <button class="load-more-comments-btn" data-post-id="<?php echo $post['id']; ?>" data-current-page="1" data-total-pages="<?php echo $totalCommentPages; ?>" style="background:none; border:none; color:#1a73e8; cursor:pointer; font-size:0.85rem; padding:10px 0;">
                                    Afficher plus de commentaires
                                </button>
                            <?php endif; ?>
                            <!-- Comment Form -->
                            <form method="POST" action="user_page.php" enctype="multipart/form-data" class="comment-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">
                                <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                <div class="wrote" style="margin-top: 20px;">
                                    <input class="wrotecomment" type="text" name="comment_content" placeholder="Ajouter un commentaire...">
                                    <button class="sendcomment" type="submit" name="submit_comment">Envoyer</button>
                                    <button type="button" class="sendMediaComment" data-post-id="<?php echo $post['id']; ?>"><i class="fa-regular fa-image"></i></button>
                                    <button type="button" class="micButtonComment" data-post-id="<?php echo $post['id']; ?>"><i class="fa-solid fa-microphone"></i></button>
                                    <input type="file" name="comment_media_file" class="comment-media-input" data-post-id="<?php echo $post['id']; ?>" style="display:none;" accept="image/*,video/*,audio/*">
                                    <input type="hidden" class="recordedCommentAudioData" name="recorded_comment_audio_data" data-post-id="<?php echo $post['id']; ?>">
                                </div>
                                <span class="recordStatusComment" data-post-id="<?php echo $post['id']; ?>"></span>
                                <div class="fileNameDisplayComment" data-post-id="<?php echo $post['id']; ?>" style="display:none;"></div>
                            </form>
                        </div>
                    <?php $postIndex++; if ($postIndex % 4 === 0): ?>
                        <!-- Affiche un encart publicitaire après chaque 4 publications -->
                        <div class="adsense-banner post-ad">
                            <ins class="adsbygoogle"
                                 style="display:inline-block;width:320px;height:100px"
                                 data-ad-client="ca-pub-XXXXXXXXXXXXXXXX"
                                 data-ad-slot="1234567890"></ins>
                            <script>
                                (adsbygoogle = window.adsbygoogle || []).push({});
                            </script>
                        </div>
                    <?php endif; ?>
                    <?php endwhile; ?>
                    <?php if ($allPosts->num_rows === 0): ?>
                        <div style="padding: 50px; text-align: center;">
                            <h3>Aucune publication pour le moment.</h3>
                            <p>Soyez le premier à partager quelque chose !</p>
                        </div>
                    <?php endif; ?>
                    <!-- Loader pour l'Infinite Scroll -->
                    <?php if ($totalPages > 1): ?>
                    <div id="posts-loader" class="loader-container" data-total-pages="<?php echo $totalPages; ?>">
                        <div class="spinner"></div>
                    </div>
                    <?php endif; ?>
                </div>
        </section>
        <article class="koloba">
                <?php if (!empty($error_msg)): ?>
                    <div style="color: red; margin-bottom: 10px; padding: 10px; border: 1px solid red; background-color: #ffe6e6; font-weight: bold;">
                        ⚠️ Erreur : <?php echo htmlspecialchars($error_msg); ?>
                    </div>
                <?php endif; ?>
                <form id="msgForm" method="post" action="user_page.php<?php echo $withUsername !== '' ? '?with=' . urlencode($withUsername) : ''; ?>" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">
                    <div class="message-recipient-row">
                        <label for="username_destinataire">Nouveau message à :</label>
                        <button type="button" id="unreadNotificationBubble" class="unread-notification-bubble" title="Voir les nouveaux messages">0</button>
                    </div>
                    <div class="search-container">
                        <input type="text" name="username_destinataire" id="username_destinataire" placeholder="Chercher un utilisateur..." autocomplete="off" value="<?php echo htmlspecialchars($withUsername, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="search-results" id="searchResults"></div>
                    </div>
                    <div id="unreadPopup" class="unread-popup" style="display:none;">
                        <div class="unread-popup-header">
                            <span>messages non lus</span>
                            <button type="button" class="close-unread-popup">×</button>
                        </div>
                        <div id="unreadPopupContent" class="unread-popup-content">Chargement...</div>
                    </div>
                <div class="conv">
                    <h2 style="margin-bottom: 16px; color: #333;">
                        <?php echo $conversationSelected ? 'Conversation avec ' . htmlspecialchars($withUsername, ENT_QUOTES, 'UTF-8') : 'Aucune conversation sélectionnée'; ?>
                    </h2>
                    <?php if ($conversationSelected && $messages && $messages->num_rows > 0): ?>
                        <?php while($msg = $messages->fetch_assoc()): ?>
                            <div class="bulle <?php echo ($msg['type'] === 'reçu' ? 'interlocuteur ' : '') . ($msg['media_type'] === 'audio' ? 'audio-msg' : ''); ?>" style="margin-bottom: 20px;">
                                <strong><?php echo ucfirst($msg['type']); ?> :</strong> <?php echo $msg['type'] === 'envoyé' ? htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') : htmlspecialchars($msg['interlocuteur'], ENT_QUOTES, 'UTF-8'); ?>
                                
                                <?php if ($msg['media_type'] === 'image'): ?>
                                    <img src="<?php echo htmlspecialchars($msg['media_url']); ?>" style="max-width: 100%; border-radius: 8px; margin-top: 5px; display: block;">
                                <?php elseif ($msg['media_type'] === 'video'): ?>
                                    <video controls style="max-width: 100%; border-radius: 8px; margin-top: 5px; display: block;">
                                        <source src="<?php echo htmlspecialchars($msg['media_url']); ?>">
                                    </video>
                                <?php elseif ($msg['media_type'] === 'audio'): ?>
                                    <div class="audio-visualizer" data-src="<?php echo htmlspecialchars($msg['media_url'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="button" class="audio-play-button">▶ Play</button>
                                        <canvas></canvas>
                                        <span class="audio-status">Charge l'audio...</span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($msg['contenu'])): ?>
                                    <span style="display:block; margin-top: 5px;"><?php echo nl2br(htmlspecialchars($msg['contenu'], ENT_QUOTES, 'UTF-8')); ?></span>
                                <?php endif; ?>
                                
                                <small class="message-info">
                                    <span class="message-date">Le <?php echo htmlspecialchars($msg['date_envoi'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php if ($msg['type'] === 'envoyé'): ?>
                                        <span class="message-seen-status"><?php echo htmlspecialchars(getMessageSeenLabel($msg['type'], $msg['lu']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </small>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="bulle" style="margin-bottom: 6px;">
                            <?php echo $conversationSelected ? 'Vous n\'avez aucun message.' : 'Aucune conversation sélectionnée.'; ?>
                        </div>
                    <?php endif; ?>
                    <div class="textarea-wrapper">
                        <span id="msg_fileNameDisplay" style="font-size: 0.85rem; color: #1a73e8; font-weight: bold; vertical-align: middle; display: none;"></span>
                        <textarea name="contenu" id="msg_contenu" rows="5" placeholder="Votre message..."></textarea>
                    </div>
                    <div>
                        <button type="submit">Envoyer</button>
                        <input type="file" name="media_file" id="msg_mediaInput" style="display:none;" accept="image/*,video/*,audio/*">
                        <input type="hidden" name="recorded_audio_data" id="msg_recordedAudioData">
                        <button type="button" class="media" style="color: white;" onclick="openMediaFile('msg');"><i class="fa-regular fa-image"></i></button>
                        <button type="button" id="msg_micButton" style="color: white;"><i class="fa-solid fa-microphone"></i></button>
                        <span id="msg_recordStatus" style="margin-left: 10px; font-size: 0.95rem; color: #1a73e8;"></span>
                    </div>
                </div>
                </form>
            </article>
        </main>
        <!-- Modal Lightbox pour les médias -->
        <div id="mediaModal" class="modal-media">
            <span class="close-media">&times;</span>
            <div class="modal-media-wrapper">
                <div class="modal-media-content" id="modalMediaContainer"></div>
                <div class="modal-comments-side" id="modalCommentsContainer"></div>
            </div>
        </div>
        <script src="https://kit.fontawesome.com/d28f9485ed.js?v=17" crossorigin="anonymous"></script>
        <!-- Socket.io pour WebRTC signaling -->
        <script src="//localhost:3000/socket.io/socket.io.js?v=17" async></script>
        <!-- Client WebRTC -->
        <script src="webrtc-client.js?v=17"></script>
        <!-- App scripts -->
        <script src="user_page.js?v=19"></script>
        <script src="pagination.js?v=18"></script>
        <script src="load_more_users.js?v=11"></script>
        <script src="livestream-clean.js?v=10"></script>
        <script defer src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@3.20.0/dist/tf.min.js"></script>
        <script defer src="https://cdn.jsdelivr.net/npm/nsfwjs@2.4.0/dist/nsfwjs.min.js"></script>
        <script>
            window.nsfwModel = null;
            window.nsfwLoaded = false;
            window.nsfwLoadError = false;

            function showNsfwStatus(message, color = ‘#333’) {
                const statusEl = document.getElementById(‘nsfwStatus’);
                if (statusEl) {
                    statusEl.textContent = message;
                    statusEl.style.color = ‘white’;
                    statusEl.style.backgroundColor = color;
                    statusEl.classList.add(‘show’);
                    if (color !== ‘#d00’) {
                        setTimeout(() => statusEl.classList.remove(‘show’), 4000);
                    }
                }
            }

            function waitForNsfwLib(callback, maxRetries = 50) {
                if (typeof nsfwjs !== ‘undefined’ && typeof tf !== ‘undefined’) {
                    callback();
                } else if (maxRetries > 0) {
                    setTimeout(() => waitForNsfwLib(callback, maxRetries - 1), 100);
                } else {
                    console.error(‘NSFWJS or TensorFlow.js failed to load’);
                    window.nsfwLoadError = true;
                    showNsfwStatus(‘[WARNING] NSFW filter unavailable’, ‘#d00’);
                }
            }

            async function loadNsfwModel() {
                if (typeof nsfwjs === ‘undefined’) {
                    console.error(‘NSFWJS library not available’);
                    window.nsfwLoadError = true;
                    showNsfwStatus(‘[WARNING] NSFW filter unavailable’, ‘#d00’);
                    return;
                }
                const modelUrl = ‘https://cdn.jsdelivr.net/gh/infinitered/nsfwjs@2.4.0/example/nsfw_demo/public/quant_nsfw_mobilenet/’;
                try {
                    if (typeof tf !== ‘undefined’ && tf.setBackend) {
                        try {
                            await tf.setBackend(‘cpu’);
                            await tf.ready();
                        } catch (e) {
                            console.warn(‘Could not set CPU backend:’, e);
                        }
                    }
                    window.nsfwModel = await nsfwjs.load(modelUrl);
                    window.nsfwLoaded = true;
                    window.nsfwLoadError = false;
                    console.log(‘[OK] NSFWJS model loaded’);
                    showNsfwStatus(‘[OK] Content filter active’, ‘#4caf50’);
                } catch (err) {
                    console.error(‘NSFWJS load failed:’, err);
                    window.nsfwLoadError = true;
                    showNsfwStatus(‘[WARNING] NSFW filter error’, ‘#d00’);
                }
            }

            function containsBlockedText(text) {
                const blockedKeywords = [
                    ‘porn’, ‘porno’, ‘pornographie’, ‘hentai’, ‘sex’, ‘sexe’, ‘sexuel’, ‘sexual’, ‘xxx’, ‘adult’,
                    ‘bdsm’, ‘erotique’, ‘erotic’, ‘erotisme’, ‘nude’, ‘nu’, ‘nue’, ‘naked’, ‘seins’, ‘boobs’,
                    ‘tits’, ‘nichon’, ‘cul’, ‘fellation’, ‘fellatio’, ‘masturb’, ‘masturbation’, ‘penetr’, ‘penis’,
                    ‘pipe’, ‘bite’, ‘couilles’, ‘chatte’, ‘vagin’, ‘anus’, ‘pussy’, ‘cock’, ‘ass’, ‘fetish’, ‘cum’
                ];
                const lower = text.toLowerCase().replace(/[^a-z0-9\s]/g, ‘ ‘);
                return blockedKeywords.some(keyword => {
                    const regex = new RegExp(‘\\b’ + keyword + ‘\\b’);
                    return regex.test(lower);
                });
            }

            async function classifyImage(file) {
                if (!window.nsfwLoaded) {
                    if (window.nsfwLoadError) {
                        console.warn(‘NSFW model unavailable’);
                        showNsfwStatus(‘[WARNING] Filter unavailable - image allowed’, ‘#ff9800’);
                        return false;
                    }
                    const start = Date.now();
                    while (!window.nsfwLoaded && !window.nsfwLoadError && (Date.now() - start) < 3000) {
                        await new Promise(r => setTimeout(r, 200));
                    }
                    if (!window.nsfwLoaded) {
                        return false;
                    }
                }

                return new Promise((resolve) => {
                    const reader = new FileReader();
                    reader.onload = async (event) => {
                        const img = new Image();
                        img.src = event.target.result;
                        img.onload = async () => {
                            try {
                                const predictions = await window.nsfwModel.classify(img);
                                if (!predictions || predictions.length === 0) {
                                    resolve(false);
                                    return;
                                }

                                const predMap = {};
                                predictions.forEach(p => {
                                    predMap[p.className.toLowerCase()] = p.probability;
                                });

                                const pornScore = predMap[‘porn’] || 0;
                                const hentaiScore = predMap[‘hentai’] || 0;
                                const sexyScore = predMap[‘sexy’] || 0;
                                const neutralScore = predMap[‘neutral’] || 0;

                                const isBlocked = (pornScore > 0.15) || (hentaiScore > 0.15) || (sexyScore > 0.80 && neutralScore < 0.10);

                                if (isBlocked) {
                                    showNsfwStatus(‘[BLOCKED] Explicit content detected’, ‘#d00’);
                                    console.warn(‘Image blocked’, {porn: pornScore, hentai: hentaiScore, sexy: sexyScore});
                                }
                                resolve(isBlocked);
                            } catch (err) {
                                console.error(‘Classification error:’, err);
                                resolve(false);
                            }
                        };
                        img.onerror = () => {
                            console.error(‘Image load error’);
                            resolve(false);
                        };
                    };
                    reader.readAsDataURL(file);
                });
            }

            document.addEventListener(‘DOMContentLoaded’, function() {
                waitForNsfwLib(loadNsfwModel);

                const postForm = document.querySelector(‘.sendPost’);
                if (postForm) {
                    postForm.addEventListener(‘submit’, async function(event) {
                        event.preventDefault();

                        const postContent = document.getElementById(‘post_contenu’);
                        const mediaInput = document.getElementById(‘post_mediaInput’);
                        const text = postContent ? postContent.value.trim() : ‘’;
                        const hasImage = mediaInput && mediaInput.files.length > 0 && mediaInput.files[0].type.toLowerCase().startsWith(‘image/’);

                        if (text && containsBlockedText(text)) {
                            showNsfwStatus(‘[BLOCKED] Explicit text detected’, ‘#d00’);
                            alert(‘Your post contains prohibited sexual content.’);
                            return;
                        }

                        if (hasImage) {
                            const blocked = await classifyImage(mediaInput.files[0]);
                            if (blocked) {
                                alert(‘The image contains explicit content and cannot be posted.’);
                                return;
                            }
                        }

                        postForm.submit();
                    });
                }

                document.addEventListener(‘submit’, async function(event) {
                    const form = event.target;
                    if (form.classList.contains(‘comment-form’)) {
                        event.preventDefault();

                        const commentInput = form.querySelector(‘.wrotecomment’);
                        const mediaInput = form.querySelector(‘.comment-media-input’);
                        const text = commentInput ? commentInput.value.trim() : ‘’;
                        const hasImage = mediaInput && mediaInput.files.length > 0 && mediaInput.files[0].type.toLowerCase().startsWith(‘image/’);

                        if (text && containsBlockedText(text)) {
                            showNsfwStatus(‘[BLOCKED] Explicit text detected’, ‘#d00’);
                            alert(‘Your comment contains prohibited sexual content.’);
                            return;
                        }

                        if (hasImage) {
                            const blocked = await classifyImage(mediaInput.files[0]);
                            if (blocked) {
                                alert(‘The image contains explicit content and cannot be posted.’);
                                return;
                            }
                        }

                        form.submit();
                    }
                }, true);
            });
        </script>
        <script>
        (function(){
            const POLL_INTERVAL = 5000;
            function escapeHtml(text) {
                return String(text)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }
            async function refreshLives(){
                try{
                    const resp = await fetch('get_active_livestreams.php', {credentials: 'same-origin'});
                    if(!resp.ok) return;
                    const data = await resp.json();
                    if(!data.success) return;
                    const liveUsers = new Set((data.livestreams||[]).map(l => String(l.user_id)));
                    document.querySelectorAll('.eloko').forEach(el => {
                        const uid = el.getAttribute('data-user-id');
                        if(!uid) return;
                        const isCurrent = String(uid) === String(window.currentUserId);
                        const isLive = liveUsers.has(String(uid));
                        if(isCurrent){
                            const btn = document.querySelector('#liveStreamButton');
                            if(btn){
                                btn.style.background = isLive ? '#4caf50' : 'red';
                                btn.textContent = isLive ? 'En direct' : 'Démarrer live';
                            }
                        } else {
                            let view = el.querySelector('.view-live-btn');
                            if(isLive){
                                if(!view){
                                    view = document.createElement('a');
                                    view.className = 'view-live-btn';
                                    const username = el.querySelector('.name-link h4') ? el.querySelector('.name-link h4').textContent.trim() : '';
                                    view.href = 'view_livestream.php?u=' + encodeURIComponent(username);
                                    view.textContent = 'Voir le live';
                                    view.style.cssText = 'background:#e53935;color:white;padding:6px 8px;border-radius:6px;text-decoration:none;';
                                    const chat = el.querySelector('.chat-icon-btn');
                                    if(chat) el.insertBefore(view, chat);
                                    else el.appendChild(view);
                                }
                            } else {
                                if(view) view.remove();
                            }
                        }
                    });
                    const activeLivesContainer = document.getElementById('activeLivesContainer');
                    if (activeLivesContainer) {
                        if ((data.livestreams || []).length === 0) {
                            activeLivesContainer.innerHTML = '<p class="active-live-empty">Aucun live en cours pour le moment.</p>';
                        } else {
                            activeLivesContainer.innerHTML = '';
                            data.livestreams.forEach(stream => {
                                const item = document.createElement('div');
                                item.className = 'active-live-item';
                                item.innerHTML = `<div class="live-user"><strong>${escapeHtml(stream.username)}</strong> <span class="live-badge">LIVE</span></div><a class="watch-btn" href="view_livestream.php?u=${encodeURIComponent(stream.username)}">Voir</a>`;
                                activeLivesContainer.appendChild(item);
                            });
                        }
                    }
                }catch(e){
                    console.error('refreshLives error', e);
                }
            }
            // initial + interval
            refreshLives();
            setInterval(refreshLives, POLL_INTERVAL);
        })();
        </script>
    </body>
</html>