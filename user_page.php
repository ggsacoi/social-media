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
        <link rel="stylesheet" href="home.css?v=11">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">

        <style>
        </style>
    </head>
    <body>
        <nav class="navi">
                <div class="search-box">
                    <h1 onclick="window.location.href='user_page.php'" style="text-decoration:none; font-size:40px; font-weight:bold; color: red; cursor:pointer;" class="logo-link">Mervie</h1>
                    <!-- <input type="search" id="username_destinataire" placeholder="Rechercher un utilisateur...">
                    <div id="searchResults" class="search-results"></div> -->
                </div>
                <form method="POST" action="user_page.php" enctype="multipart/form-data" class="sendPost">
                    <input type="text" name="legende" id="post_contenu" placeholder="Quoi de neuf ?">
                    <button type="submit" name="submit_post" class="btn-send">Poster</button>
                    <button type="button" class="sendMedia" onclick="openMediaFile('post')"><i class="fa-regular fa-image"></i></button>
                    <button type="button" id="post_micButton"><i class="fa-solid fa-microphone"></i></button>
                    <input type="file" name="media_file" id="post_mediaInput" style="display:none;" accept="image/*,video/*,audio/*">
                    <input type="hidden" id="post_recordedAudioData" name="recorded_audio_data">
                </form>
                <span id="post_recordStatus"></span>
                <div id="post_fileNameDisplay" style="display:none;"></div>

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
                            <div class="eloko">
                                <a href="profil.php?u=<?php echo urlencode($sideUser['username']); ?>" class="avatar-link">
                                    <span class="people" style="<?php echo !empty($sideUser['profile_pic']) ? "background-image: url('" . htmlspecialchars($sideUser['profile_pic']) . "'); background-size: cover;" : ''; ?>">
                                        <?php if(empty($sideUser['profile_pic'])): ?><i class="fa-solid fa-user"></i><?php endif; ?>
                                    </span>
                                </a>
                                <a href="profil.php?u=<?php echo urlencode($sideUser['username']); ?>" class="name-link">
                                    <h4><?php echo htmlspecialchars($sideUser['username']); ?></h4>
                                </a>
                                <a href="user_page.php?with=<?php echo urlencode($sideUser['username']); ?>" class="chat-icon-btn" title="Envoyer un message">
                                    <i class="fa-regular fa-comment-dots"></i>
                                </a>
                            </div>
                        <?php endwhile; ?>
                    </div>
                    <?php if ($totalUserPages > 1): ?>
                        <button id="load-more-users" data-total-pages="<?php echo $totalUserPages; ?>">Afficher plus</button>
                    <?php endif; ?>
                </article>
        <section class="news">
                <div class="content">
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

                            <!-- Comment Form -->
                            <form method="POST" action="user_page.php" enctype="multipart/form-data" class="comment-form">
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
                                    <div class="comment-item">
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
                        </div>
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

                <!-- <button style="color: black;" onclick="window.location.href='logout.php'">logout</button> -->
        </section>
        <article class="koloba">
                <?php if (!empty($error_msg)): ?>
                    <div style="color: red; margin-bottom: 10px; padding: 10px; border: 1px solid red; background-color: #ffe6e6; font-weight: bold;">
                        ⚠️ Erreur : <?php echo htmlspecialchars($error_msg); ?>
                    </div>
                <?php endif; ?>

                <form id="msgForm" method="post" action="user_page.php<?php echo $withUsername !== '' ? '?with=' . urlencode($withUsername) : ''; ?>" enctype="multipart/form-data">
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

        <script src="https://kit.fontawesome.com/d28f9485ed.js" crossorigin="anonymous"></script>
        <script src="user_page.js?v=7"></script>
        <script src="pagination.js?v=8"></script>
    </body>
</html>