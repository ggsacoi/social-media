<?php
// post_handler.php - Gestion des posts et commentaires

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_post'])) {
    // Validation du token CSRF
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        die('Erreur de sécurité CSRF. Veuillez réessayer.');
    }
    
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
    // Validation du token CSRF
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        die('Erreur de sécurité CSRF. Veuillez réessayer.');
    }
    
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
                // Si c'est un envoi AJAX, renvoyer seulement le HTML du nouveau commentaire
                if (isset($_GET['ajax_submit'])) {
                    $lastId = $conn->insert_id;
                    $stmtNew = $conn->prepare("SELECT c.*, u.username, u.profile_pic FROM comments c JOIN users u ON c.user_id = u.id WHERE c.id = ?");
                    $stmtNew->bind_param("i", $lastId);
                    $stmtNew->execute();
                    $comment = $stmtNew->get_result()->fetch_assoc();
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
                    <?php
                    exit;
                }

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
?>