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

// Diagnostic de connexion au serveur de modération Node.js
$moderationOnline = false;
$socket = @fsockopen('127.0.0.1', 3000, $errno, $errstr, 0.1);
if ($socket) {
    $moderationOnline = true;
    fclose($socket);
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>lemonde</title>
        <link rel="stylesheet" href="home.css?v=14">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
        <!-- Google AdSense : chargement asynchrone de la librairie publicitaire -->
        <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1336558294811026" crossorigin="anonymous"></script>
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
                cursor: pointer;
            }
            .active-live-list .show-more-btn {
                background: #e53935;
                color: white;
                padding: 6px 10px;
                border-radius: 8px;
                border: none;
                font-size: 0.9rem;
                cursor: pointer;
                display: block;
                margin: 10px auto 0;
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
                    <div class="post-actions-dropdown">
                        <button type="button" class="dropdown-trigger" id="postActionsDropdownTrigger" title="Plus d'options"><i class="fa-solid fa-plus"></i> Options</button>
                        <div class="dropdown-menu">
                            <button type="button" class="sendMedia" onclick="openMediaFile('post')"><i class="fa-regular fa-image"></i> Média</button>
                            <button type="button" id="post_micButton"><i class="fa-solid fa-microphone"></i> Vocal</button>
                            <button type="button" id="liveStreamButton"><i class="fa-solid fa-tower-broadcast"></i> Direct</button>
                        </div>
                    </div>
                    <input type="file" name="media_file" id="post_mediaInput" style="display:none;" accept="image/*,video/*,audio/*">
                    <input type="hidden" id="post_recordedAudioData" name="recorded_audio_data">
                    <div id="post_fileNameDisplay" style="display:none;"></div>
                </form>
                <span id="post_recordStatus"></span>
                <!-- <div class="adsense-banner">
                    Bloc publicitaire AdSense : remplacez ca-pub et data-ad-slot par vos propres identifiants
                    <ins class="adsbygoogle"
                         style="display:inline-block;width:320px;height:100px"
                         data-ad-client="ca-pub-1336558294811026"
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
                                <span style="color:#666;font-size:0.9rem;">&nbsp;</span>
                                <a href="user_page.php?with=<?php echo urlencode($sideUser['username']); ?>" class="chat-icon-btn" title="Envoyer un message">
                                    <i class="fa-regular fa-comment-dots"></i>
                                </a>
                            </div>
                        <?php endwhile; ?>
                    </div>
                    <?php if ($totalUserPages > 1): ?>
                        <button id="load-more-users" data-total-pages="<?php echo $totalUserPages; ?>" data-seed="<?php echo $commentSeed; ?>">Afficher plus</button>
                    <?php endif; ?>
                    <div id="active-live-list" class="active-live-list">
                        <h3>Lives en cours</h3>
                        <div id="activeLivesContainer">
                            <p class="active-live-empty">Chargement des lives en cours...</p>
                        </div>
                    </div>
                </article>
        <section class="news">
                <?php if (!$moderationOnline): ?>
                    <div style="background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 15px 20px; border-radius: 8px; margin: 20px; font-weight: bold; display: flex; align-items: center; gap: 12px; font-family: 'Poppins', sans-serif; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                        <i class="fa-solid fa-circle-exclamation" style="font-size: 1.4rem; color: #856404;"></i>
                        <div>
                            <span style="font-size: 1rem; display: block; margin-bottom: 2px; color: #856404;">⚠️ Serveur de Modération Hors Ligne</span>
                            <span style="font-weight: normal; font-size: 0.9rem; color: #856404;">Le serveur Node.js est arrêté. La détection de nudité NudeNet est <strong>désactivée</strong> (les vidéos ne sont pas modérées). Pour l'activer, double-cliquez sur <code>webrtc-server/start-server.bat</code>.</span>
                        </div>
                    </div>
                <?php endif; ?>
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
                                 data-ad-client="ca-pub-1336558294811026"
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
                        <label>Mes abonnements :</label>
                        <button type="button" id="unreadNotificationBubble" class="unread-notification-bubble" title="Voir les nouveaux messages">0</button>
                    </div>
                    <input type="hidden" name="username_destinataire" id="username_destinataire" value="<?php echo htmlspecialchars($withUsername, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="following-chat-list" style="display: flex; gap: 15px; overflow-x: auto; padding: 10px 5px; margin-bottom: 20px; border-bottom: 1px solid #eee; scrollbar-width: thin; scrollbar-color: #ccc #f1f1f1;">
                        <style>
                            .following-chat-list::-webkit-scrollbar {
                                height: 4px;
                            }
                            .following-chat-list::-webkit-scrollbar-track {
                                background: #f1f1f1;
                                border-radius: 10px;
                            }
                            .following-chat-list::-webkit-scrollbar-thumb {
                                background: #ccc;
                                border-radius: 10px;
                                transition: background 0.2s ease;
                            }
                            .following-chat-list::-webkit-scrollbar-thumb:hover {
                                background: #e53935;
                            }
                            .following-item {
                                display: flex;
                                flex-direction: column;
                                align-items: center;
                                gap: 6px;
                                cursor: pointer;
                                text-decoration: none;
                                min-width: 65px;
                                transition: transform 0.2s ease, opacity 0.2s ease;
                            }
                            .following-item:hover {
                                transform: scale(1.08);
                            }
                            .following-avatar {
                                width: 52px;
                                height: 52px;
                                border-radius: 50%;
                                background-size: cover;
                                background-position: center;
                                background-color: #ddd;
                                border: 2px solid #ddd;
                                display: flex;
                                justify-content: center;
                                align-items: center;
                                font-size: 1.2rem;
                                color: #666;
                                transition: border-color 0.2s ease, box-shadow 0.2s ease;
                            }
                            .following-item.active .following-avatar {
                                border-color: red;
                                box-shadow: 0 0 8px rgba(255, 0, 0, 0.3);
                            }
                            .following-name {
                                font-size: 0.75rem;
                                font-weight: 600;
                                color: #333;
                                max-width: 65px;
                                overflow: hidden;
                                text-overflow: ellipsis;
                                white-space: nowrap;
                            }
                        </style>
                        <?php if ($followingList && $followingList->num_rows > 0): ?>
                            <?php while($fUser = $followingList->fetch_assoc()): ?>
                                <?php 
                                    $isActive = ($withUsername === $fUser['username']);
                                    $pfp = !empty($fUser['profile_pic']) ? $fUser['profile_pic'] : 'default.png';
                                    if ($pfp === 'default.png' || empty($fUser['profile_pic'])) {
                                        $pfpUrl = '';
                                    } else {
                                        $pfpUrl = htmlspecialchars($fUser['profile_pic']);
                                    }
                                ?>
                                <a href="user_page.php?with=<?php echo urlencode($fUser['username']); ?>" class="following-item <?php echo $isActive ? 'active' : ''; ?>" title="<?php echo htmlspecialchars($fUser['username']); ?>">
                                    <div class="following-avatar" style="<?php echo !empty($pfpUrl) ? "background-image: url('" . $pfpUrl . "');" : ''; ?>">
                                        <?php if(empty($pfpUrl)): ?><i class="fa-solid fa-user"></i><?php endif; ?>
                                    </div>
                                    <span class="following-name"><?php echo htmlspecialchars($fUser['username']); ?></span>
                                </a>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p style="font-size: 0.85rem; color: #888; padding: 10px; width: 100%; text-align: center;">Vous ne suivez personne.</p>
                        <?php endif; ?>
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
        <script src="load_more_users.js?v=12"></script>
        <script src="livestream-clean.js?v=10"></script>
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
                let data = null;
                try{
                    // Tenter de récupérer depuis le serveur Node.js (temps réel)
                    const resp = await fetch('http://localhost:3000/livestreams');
                    if(resp.ok) {
                        const parsed = await resp.json();
                        if(parsed.success) data = parsed;
                    }
                }catch(e){
                    console.warn('[refreshLives] Impossible de contacter le serveur Node.js, bascule vers la base locale...');
                }

                // Repli : récupérer depuis le script PHP local (base de données)
                if (!data) {
                    try {
                        const resp = await fetch('get_active_livestreams.php');
                        if (resp.ok) {
                            const parsed = await resp.json();
                            if (parsed.success) data = parsed;
                        }
                    } catch(err) {
                        console.error('[refreshLives] Erreur lors de la récupération locale:', err);
                    }
                }

                if (!data) return;

                const liveUsers = new Set((data.livestreams||[]).map(l => String(l.user_id)));
                const isOurLiveActive = liveUsers.has(String(window.currentUserId));
                const navLiveBtn = document.getElementById('liveStreamButton');
                if (navLiveBtn) {
                    navLiveBtn.style.background = isOurLiveActive ? '#4caf50' : 'red';
                }
                const activeLivesContainer = document.getElementById('activeLivesContainer');
                if (activeLivesContainer) {
                    if ((data.livestreams || []).length === 0) {
                        activeLivesContainer.innerHTML = '<p class="active-live-empty">Aucun live en cours pour le moment.</p>';
                    } else {
                        activeLivesContainer.innerHTML = '';
                        const visibleStreams = data.livestreams.slice(0, window.activeLivesLimit);
                        visibleStreams.forEach(stream => {
                            const item = document.createElement('div');
                            item.className = 'active-live-item';
                            item.innerHTML = `<div class="live-user"><strong>${escapeHtml(stream.username)}</strong> <span class="live-badge">LIVE</span></div><a class="watch-btn" href="view_livestream.php?u=${encodeURIComponent(stream.username)}">Voir</a>`;
                            activeLivesContainer.appendChild(item);
                        });

                        if (data.livestreams.length > window.activeLivesLimit) {
                            const showMoreBtn = document.createElement('button');
                            showMoreBtn.className = 'show-more-btn';
                            showMoreBtn.textContent = 'Afficher plus';
                            showMoreBtn.addEventListener('click', () => {
                                window.activeLivesLimit += 5;
                                refreshLives();
                            });
                            activeLivesContainer.appendChild(showMoreBtn);
                        }
                    }
                }
            }
            // initial + interval
            window.activeLivesLimit = 5;
            refreshLives();
            setInterval(refreshLives, POLL_INTERVAL);
        })();
        </script>
    </body>
</html>