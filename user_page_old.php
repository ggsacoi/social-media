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

// --- LOGIQUE D'ENVOI (Issue de document.php) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['submit_post']) && !isset($_POST['submit_comment'])) {
    error_log("POST request received"); // Debug log
    $recipientUsername = trim($_POST['username_destinataire'] ?? '');
    $withUsernameFromGet = trim($_GET['with'] ?? '');
    
    // Priorité au destinataire de la conversation active si le champ est vide
    if ($recipientUsername === '' && $withUsernameFromGet !== '') {
        $recipientUsername = $withUsernameFromGet;
    }
    
    $contenu = trim($_POST['contenu'] ?? '');
    $mediaUrl = '';
    $mediaType = 'texte';

    error_log("Recipient: $recipientUsername, Content: " . substr($contenu, 0, 50) . "..."); // Debug log

    // Gestion de l'enregistrement audio depuis le microphone
    if (!empty($_POST['recorded_audio_data'])) {
        $recordedData = $_POST['recorded_audio_data'];
        error_log("Audio data received: " . substr($recordedData, 0, 100) . "..."); // Debug log
        
        if (preg_match('/^data:audio\/([a-zA-Z0-9.+-]+);base64,(.*)$/', $recordedData, $matches)) {
            $audioExt = strtolower($matches[1]);
            $audioContent = base64_decode($matches[2]);
            error_log("Audio extension: $audioExt, Content length: " . strlen($audioContent)); // Debug log
            
            if ($audioContent !== false) {
                $allowedAudioExts = ['webm', 'wav', 'ogg', 'mp3'];
                if (!in_array($audioExt, $allowedAudioExts)) {
                    $audioExt = 'webm';
                    error_log("Extension changed to: $audioExt"); // Debug log
                }
                $uploadDir = __DIR__ . '/uploads/messages/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                    error_log("Created directory: $uploadDir"); // Debug log
                }
                $newFilename = uniqid() . '.' . $audioExt;
                $targetPath = $uploadDir . $newFilename;
                error_log("Target path: $targetPath"); // Debug log
                
                if (file_put_contents($targetPath, $audioContent) !== false) {
                    $mediaUrl = 'uploads/messages/' . $newFilename;
                    $mediaType = 'audio';
                    error_log("Audio saved successfully: $mediaUrl"); // Debug log
                } else {
                    $error_msg = "Impossible d'enregistrer l'audio. Erreur d'écriture du fichier.";
                    error_log("Failed to save audio file: $targetPath"); // Debug log
                }
            } else {
                $error_msg = "Erreur de décodage des données audio.";
                error_log("Base64 decode failed"); // Debug log
            }
        } else {
            $error_msg = "Format de données audio invalide.";
            error_log("Invalid audio data format: " . substr($recordedData, 0, 50)); // Debug log
        }
    }

    // Gestion de l'upload Media classique (image/vidéo/audio)
    if ($mediaUrl === '' && isset($_FILES['media_file']) && $_FILES['media_file']['error'] === 0) {
        $filename = $_FILES['media_file']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $imgExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'jfif'];
        $vidExts = ['mp4', 'webm', 'ogg'];
        $audExts = ['mp3', 'wav', 'ogg'];

        if (in_array($ext, $imgExts)) $mediaType = 'image';
        elseif (in_array($ext, $vidExts)) $mediaType = 'video';
        elseif (in_array($ext, $audExts)) $mediaType = 'audio';
        else {
            $error_msg = "Type de fichier non supporté : " . $ext;
        }

        if ($mediaType !== 'texte') {
            $uploadDir = __DIR__ . '/uploads/messages/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $newFilename = uniqid() . '.' . $ext;
            $targetPath = $uploadDir . $newFilename;
            
            if(move_uploaded_file($_FILES['media_file']['tmp_name'], $targetPath)) {
                $mediaUrl = 'uploads/messages/' . $newFilename;
            } else {
                $error_msg = "Erreur lors de l'upload du fichier. Code erreur: " . $_FILES['media_file']['error'];
            }
        }
    } elseif (!isset($_FILES['media_file']) || $_FILES['media_file']['error'] === 4) {
        // aucun fichier sélectionné ; c'est ok si on a un message texte ou un enregistrement audio
    } elseif (isset($_FILES['media_file']) && $_FILES['media_file']['error'] !== 0) {
        $error_msg = "Erreur de fichier uploadé. Code: " . $_FILES['media_file']['error'];
    }

    // On envoie si on a soit du texte, soit un média
    if ($recipientUsername !== '' && ($contenu !== '' || $mediaUrl !== '') && $recipientUsername !== $userName) {
        error_log("Sending message - Recipient: $recipientUsername, Content: " . substr($contenu, 0, 50) . ", Media: $mediaUrl, Type: $mediaType"); // Debug log
        $stmt = $conn->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->bind_param('s', $recipientUsername);
        $stmt->execute();
        $destRes = $stmt->get_result();

        if ($destRes->num_rows > 0) {
            $recipientId = $destRes->fetch_assoc()['id'];
            $dateEnvoi = date('Y-m-d H:i:s');
            $stmt = $conn->prepare('INSERT INTO messages (id_expediteur, id_destinataire, contenu, media_url, media_type, date_envoi, lu) VALUES (?, ?, ?, ?, ?, ?, 0)');
            $stmt->bind_param('iissss', $userId, $recipientId, $contenu, $mediaUrl, $mediaType, $dateEnvoi);
            if ($stmt->execute()) {
                error_log("Message sent successfully to: $recipientUsername"); // Debug log
                header('Location: user_page.php?with=' . urlencode($recipientUsername));
                exit();
            } else {
                $error_msg = "Erreur lors de l'envoi du message : " . $stmt->error;
                error_log("Failed to send message: " . $stmt->error); // Debug log
            }
        } else {
            $error_msg = "Utilisateur introuvable.";
            error_log("User not found: $recipientUsername"); // Debug log
        }
    } else {
        // Debug: vérifier pourquoi le message n'est pas envoyé
        $debug_msg = "Message non envoyé. Raison: ";
        if ($recipientUsername === '') {
            $debug_msg .= "Destinataire vide. ";
        }
        if ($contenu === '' && $mediaUrl === '') {
            $debug_msg .= "Pas de contenu ni média. ";
        }
        if ($recipientUsername === $userName) {
            $debug_msg .= "Destinataire = expéditeur. ";
        }
        error_log($debug_msg); // Debug log
        
        // Afficher un message d'erreur seulement si c'est une tentative d'envoi réelle
        if ($recipientUsername !== '' || $contenu !== '' || $mediaUrl !== '') {
            $error_msg = "Impossible d'envoyer le message. Vérifiez que vous avez un destinataire et du contenu.";
        }
    }
}

// --- LOGIQUE DE PUBLICATION (POSTS) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_post'])) {
    $legende = trim($_POST['legende'] ?? '');
    error_log("Attempting to create post. Legende: " . substr($legende, 0, 50));
    if (isset($_FILES['media_file'])) {
        error_log("FILES for media_file in post: " . print_r($_FILES['media_file'], true));
    }

    $mediaUrl = '';
    $mediaType = 'texte';

    // Gestion Audio (Microphone)
    if (!empty($_POST['recorded_audio_data'])) {
        $recordedData = $_POST['recorded_audio_data'];
        if (preg_match('/^data:audio\/([a-zA-Z0-9.+-]+);base64,(.*)$/', $recordedData, $matches)) {
            $audioExt = strtolower($matches[1]);
            $audioContent = base64_decode($matches[2]);
            
            error_log("Processing recorded audio for post. Size: " . strlen($audioContent));
            $uploadDir = __DIR__ . '/uploads/posts/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $newFilename = 'post_audio_' . uniqid() . '.webm';
            $targetPath = $uploadDir . $newFilename;
            if (file_put_contents($targetPath, $audioContent)) {
                $mediaUrl = 'uploads/posts/' . $newFilename;
                $mediaType = 'audio';
                error_log("Recorded audio saved for post: " . $mediaUrl);
            } else {
                error_log("Failed to save recorded audio for post to: " . $targetPath);
            }
        }
    }

    // Gestion Media (Upload fichier)
    if ($mediaUrl === '' && isset($_FILES['media_file']) && $_FILES['media_file']['error'] === 0) {
        error_log("Processing uploaded media file for post.");
        $filename = $_FILES['media_file']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $imgExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'jfif'];
        $vidExts = ['mp4', 'webm', 'ogg'];
        $audExts = ['mp3', 'wav', 'ogg'];

        if (in_array($ext, $imgExts)) $mediaType = 'image';
        elseif (in_array($ext, $vidExts)) $mediaType = 'video';
        elseif (in_array($ext, $audExts)) $mediaType = 'audio';
        else {
            error_log("Unsupported media type for post upload: " . $ext);
        }

        $uploadDir = __DIR__ . '/uploads/posts/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        
        $newFilename = 'post_media_' . uniqid() . '.' . $ext;
        $targetPath = $uploadDir . $newFilename;
        error_log("Target path for post media upload: " . $targetPath);
        
        if (move_uploaded_file($_FILES['media_file']['tmp_name'], $targetPath)) {
            $mediaUrl = 'uploads/posts/' . $newFilename;
            error_log("Uploaded media saved for post: " . $mediaUrl);
        } else {
            error_log("Failed to move uploaded file for post. Error code: " . $_FILES['media_file']['error'] . " Temp name: " . $_FILES['media_file']['tmp_name'] . " Target path: " . $targetPath);
        }
    } elseif (isset($_FILES['media_file']) && $_FILES['media_file']['error'] !== 0) {
        error_log("File upload error for post: " . $_FILES['media_file']['error']);
    }

    if (!empty($legende) || !empty($mediaUrl)) {
        $stmtPost = $conn->prepare('INSERT INTO posts (user_id, legende, media_url, media_type) VALUES (?, ?, ?, ?)');
        $stmtPost->bind_param('isss', $userId, $legende, $mediaUrl, $mediaType);
        $stmtPost->execute();
        error_log("Post inserted into DB. Media URL: " . $mediaUrl . ", Media Type: " . $mediaType);
        header("Location: user_page.php");
        exit();
    }
}

// --- LOGIQUE D'ENVOI DE COMMENTAIRE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_comment'])) {
    $postId = $_POST['post_id'] ?? null;
    $commentContent = trim($_POST['comment_content'] ?? '');
    $commentMediaUrl = '';
    $commentMediaType = 'texte';

    if (!$postId) {
        $error_msg = "ID du post manquant pour le commentaire.";
        error_log("Comment submission error: Post ID missing.");
    } else {
        // Handle recorded audio for comment
        if (!empty($_POST['recorded_comment_audio_data'])) {
            $recordedData = $_POST['recorded_comment_audio_data'];
            if (preg_match('/^data:audio\/([a-zA-Z0-9.+-]+);base64,(.*)$/', $recordedData, $matches)) {
                $audioExt = strtolower($matches[1]);
                $audioContent = base64_decode($matches[2]);
                
                $uploadDir = __DIR__ . '/uploads/comments/';
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0777, true)) {
                        error_log("Failed to create directory for comment audio: " . $uploadDir);
                    }
                }
                
                $newFilename = 'comment_audio_' . uniqid() . '.webm';
                $targetPath = $uploadDir . $newFilename;
                if (file_put_contents($targetPath, $audioContent)) {
                    $commentMediaUrl = 'uploads/comments/' . $newFilename;
                    $commentMediaType = 'audio';
                } else {
                    error_log("Failed to save recorded audio for comment to: " . $targetPath);
                }
            }
        }

        // Handle uploaded media for comment
        if ($commentMediaUrl === '' && isset($_FILES['comment_media_file']) && $_FILES['comment_media_file']['error'] === 0) {
            $filename = $_FILES['comment_media_file']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            $imgExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'jfif'];
            $vidExts = ['mp4', 'webm', 'ogg'];
            $audExts = ['mp3', 'wav', 'ogg'];

            if (in_array($ext, $imgExts)) $commentMediaType = 'image';
            elseif (in_array($ext, $vidExts)) $commentMediaType = 'video';
            elseif (in_array($ext, $audExts)) $commentMediaType = 'audio';
            else {
                error_log("Unsupported media type for comment upload: " . $ext);
            }

            $uploadDir = __DIR__ . '/uploads/comments/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0777, true)) {
                    error_log("Failed to create directory for comment media: " . $uploadDir);
                }
            }
            
            $newFilename = 'comment_media_' . uniqid() . '.' . $ext;
            $targetPath = $uploadDir . $newFilename;
            
            if (move_uploaded_file($_FILES['comment_media_file']['tmp_name'], $targetPath)) {
                $commentMediaUrl = 'uploads/comments/' . $newFilename;
            } else {
                error_log("Failed to move uploaded file for comment. Error code: " . $_FILES['comment_media_file']['error']);
            }
        }

        if (!empty($commentContent) || !empty($commentMediaUrl)) {
            $stmtComment = $conn->prepare('INSERT INTO comments (post_id, user_id, contenu, media_url, media_type) VALUES (?, ?, ?, ?, ?)');
            $stmtComment->bind_param('iisss', $postId, $userId, $commentContent, $commentMediaUrl, $commentMediaType);
            if ($stmtComment->execute()) {
                header("Location: user_page.php#post-" . $postId); // Redirect to the post
                exit();
            } else {
                $error_msg = "Erreur lors de l'envoi du commentaire : " . $stmtComment->error;
                error_log("Failed to send comment: " . $stmtComment->error);
            }
        } else {
            $error_msg = "Le commentaire ne peut pas être vide.";
        }
    }
}

// AJAX pour charger plus de commentaires
if (isset($_GET['ajax_comments'])) {
    $cPostId = (int)$_GET['post_id'];
    $all = isset($_GET['all']);

    if ($all) {
        $stmtCommentsAjax = $conn->prepare("SELECT c.*, u.username, u.profile_pic FROM comments c JOIN users u ON c.user_id = u.id WHERE c.post_id = ? ORDER BY RAND($commentSeed + c.id)");
        $stmtCommentsAjax->bind_param('i', $cPostId);
    } else {
        $cPage = (int)$_GET['c_page'];
        $cLimit = 5;
        // Page 1 affichait 1 seul commentaire. Page 2 commence à l'offset 1.
        $cOffset = 1 + (($cPage - 2) * $cLimit);
        $stmtCommentsAjax = $conn->prepare("SELECT c.*, u.username, u.profile_pic FROM comments c JOIN users u ON c.user_id = u.id WHERE c.post_id = ? ORDER BY RAND($commentSeed + c.id) LIMIT ? OFFSET ?");
        $stmtCommentsAjax->bind_param('iii', $cPostId, $cLimit, $cOffset);
    }

    $stmtCommentsAjax->execute();
    $ajaxComments = $stmtCommentsAjax->get_result();
    while($comment = $ajaxComments->fetch_assoc()): ?>
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
    $stmtCommentsAjax->close();
    exit;
}

// --- LOGIQUE DE PAGINATION ---
$limit = 20; 
$currentPage = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;
$offset = ($currentPage - 1) * $limit;

$totalPostsRes = $conn->query("SELECT COUNT(*) as total FROM posts");
$totalPosts = $totalPostsRes->fetch_assoc()['total'];
$totalPages = ceil($totalPosts / $limit);

$allPosts = $conn->query("SELECT p.*, u.username, u.profile_pic FROM posts p JOIN users u ON p.user_id = u.id ORDER BY p.date_publication DESC LIMIT $limit OFFSET $offset");

if (isset($_GET['ajax'])) {
    while($post = $allPosts->fetch_assoc()): ?>
        <div class="post-item" id="post-<?php echo $post['id']; ?>">
            <div class="top">
                <div class="character">
                    <span class="people" style="background-image: url('<?php echo htmlspecialchars($post['profile_pic']); ?>'); background-size: cover;">
                        <?php if(empty($post['profile_pic'])): ?><i class="fa-solid fa-user"></i><?php endif; ?>
                    </span> 
                    <h3><?php echo htmlspecialchars($post['username']); ?></h3>
                </div>
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
    <?php endwhile;
    exit;
}

// AJAX pour charger plus d'utilisateurs dans la barre latérale
if (isset($_GET['ajax_users'])) {
    $uLimit = 9;
    $uPage = isset($_GET['user_page']) ? (int)$_GET['user_page'] : 2;
    $uOffset = ($uPage - 1) * $uLimit;
    $sUsers = $conn->query("SELECT username, profile_pic FROM users ORDER BY username ASC LIMIT $uLimit OFFSET $uOffset");
    while($su = $sUsers->fetch_assoc()): ?>
        <div class="eloko">
            <a href="profil.php?u=<?php echo urlencode($su['username']); ?>" class="avatar-link">
                <span class="people" style="<?php echo !empty($su['profile_pic']) ? "background-image: url('" . htmlspecialchars($su['profile_pic']) . "'); background-size: cover;" : ''; ?>">
                    <?php if(empty($su['profile_pic'])): ?><i class="fa-solid fa-user"></i><?php endif; ?>
                </span>
            </a>
            <a href="profil.php?u=<?php echo urlencode($su['username']); ?>" class="name-link">
                <h4><?php echo htmlspecialchars($su['username']); ?></h4>
            </a>
            <a href="user_page.php?with=<?php echo urlencode($su['username']); ?>" class="chat-icon-btn" title="Envoyer un message">
                <i class="fa-regular fa-comment-dots"></i>
            </a>
        </div>
    <?php endwhile;
    exit;
}


// Récupération de tous les utilisateurs pour la barre latérale (bomoto)
$userLimit = 9;
$totalUsersRes = $conn->query("SELECT COUNT(*) as total FROM users");
$totalUsersCount = $totalUsersRes->fetch_assoc()['total'];
$totalUserPages = ceil($totalUsersCount / $userLimit);
$sidebarUsers = $conn->query("SELECT username, profile_pic FROM users ORDER BY username ASC LIMIT $userLimit");

$withUsername = trim($_GET['with'] ?? '');
$withId = null;
if ($withUsername !== '') {
    $withStmt = $conn->prepare('SELECT id FROM users WHERE username = ?');
    if (!$withStmt) {
        die("Erreur de préparation de la requête interlocuteur: " . $conn->error);
    }
    $withStmt->bind_param('s', $withUsername);
    if (!$withStmt->execute()) {
        die("Erreur d'exécution de la requête interlocuteur: " . $withStmt->error);
    }
    $withResult = $withStmt->get_result();
    if ($withResult->num_rows > 0) {
        $with = $withResult->fetch_assoc();
        $withId = $with['id'];
    }
}

if ($withId !== null) {
    $msgStmt = $conn->prepare('SELECT m.id, m.contenu, m.media_url, m.media_type, m.date_envoi, u.username AS interlocuteur, IF(m.id_destinataire = ?, "reçu", "envoyé") AS type FROM messages m JOIN users u ON IF(m.id_destinataire = ?, m.id_expediteur, m.id_destinataire) = u.id WHERE (m.id_destinataire = ? AND m.id_expediteur = ?) OR (m.id_destinataire = ? AND m.id_expediteur = ?) ORDER BY m.date_envoi ASC');
    if (!$msgStmt) {
        die("Erreur de préparation de la requête messages: " . $conn->error);
    }
    $msgStmt->bind_param('iiiiii', $userId, $userId, $userId, $withId, $withId, $userId);
} else {
    $msgStmt = $conn->prepare('SELECT m.id, m.contenu, m.media_url, m.media_type, m.date_envoi, u.username AS interlocuteur, IF(m.id_destinataire = ?, "reçu", "envoyé") AS type FROM messages m JOIN users u ON IF(m.id_destinataire = ?, m.id_expediteur, m.id_destinataire) = u.id WHERE m.id_destinataire = ? OR m.id_expediteur = ? ORDER BY m.date_envoi ASC');
    if (!$msgStmt) {
        die("Erreur de préparation de la requête messages: " . $conn->error);
    }
    $msgStmt->bind_param('iiii', $userId, $userId, $userId, $userId);
}
if (!$msgStmt->execute()) {
    die("Erreur d'exécution de la requête messages: " . $msgStmt->error);
}
$messages = $msgStmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>lemonde</title>
        <link rel="stylesheet" href="home.css?v=10">
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
                                <div class="character">
                                    <span class="people" style="background-image: url('<?php echo htmlspecialchars($post['profile_pic']); ?>'); background-size: cover;">
                                        <?php if(empty($post['profile_pic'])): ?><i class="fa-solid fa-user"></i><?php endif; ?>
                                    </span> 
                                    <h3><?php echo htmlspecialchars($post['username']); ?></h3>
                                </div>
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
                    <label for="username_destinataire">Nouveau message à :</label>
                    <div class="search-container">
                        <input type="text" name="username_destinataire" id="username_destinataire" placeholder="Chercher un utilisateur..." autocomplete="off">
                        <div class="search-results" id="searchResults"></div>
                    </div>

                <div class="conv">
                    <h2 style="margin-bottom: 16px; color: #333;">
                        <?php echo $withUsername !== '' ? 'Conversation avec ' . htmlspecialchars($withUsername, ENT_QUOTES, 'UTF-8') : 'Messages reçus'; ?>
                    </h2>
                    <?php if($messages->num_rows > 0): ?>
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
                                
                                <small style="display:block; color:#555;">Le <?php echo htmlspecialchars($msg['date_envoi'], ENT_QUOTES, 'UTF-8'); ?></small>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="bulle" style="margin-bottom: 6px;">Vous n'avez aucun message.</div>
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
        <script src="user_page.js?v=5"></script>
        <script src="pagination.js?v=8"></script>
    </body>
</html>