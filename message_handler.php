<?php
// message_handler.php - Gestion des messages

// --- LOGIQUE D'ENVOI (Issue de document.php) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['submit_post']) && !isset($_POST['submit_comment'])) {
    // Validation du token CSRF
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        die('Erreur de sécurité CSRF. Veuillez réessayer.');
    }
    
    error_log("POST request received"); // Debug log
    $recipientUsername = trim($_POST['username_destinataire'] ?? '');
    $withUsernameFromGet = trim($_GET['with'] ?? '');

    // Si aucun destinataire n'est saisi, on utilise la conversation ouverte si elle existe.
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
                $newFilename = 'message_audio_' . uniqid() . '.' . $audioExt;
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
        $mime = $_FILES['media_file']['type'] ?? '';
        
        $imgExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'jfif'];
        $vidExts = ['mp4', 'webm', 'ogg', 'mov', 'avi'];
        $audExts = ['mp3', 'wav', 'ogg', 'webm', 'm4a', 'aac'];

        if (strpos($mime, 'image/') === 0) $mediaType = 'image';
        elseif (strpos($mime, 'video/') === 0) $mediaType = 'video';
        elseif (strpos($mime, 'audio/') === 0) $mediaType = 'audio';
        elseif (in_array($ext, $imgExts)) $mediaType = 'image';
        elseif (in_array($ext, $vidExts)) $mediaType = 'video';
        elseif (in_array($ext, $audExts)) $mediaType = 'audio';
        else {
            $error_msg = "Type de fichier non supporté : " . $ext;
        }

        if ($mediaType !== 'texte') {
            $uploadDir = __DIR__ . '/uploads/messages/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $prefix = ($mediaType === 'audio') ? 'message_audio_' : (($mediaType === 'video') ? 'message_video_' : 'message_media_');
            $newFilename = $prefix . uniqid() . '.' . $ext;
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
        // Modération du message (texte et image)
        $moderationResult = moderateContent($contenu, $mediaUrl);
        if (!$moderationResult['safe']) {
            $error_msg = $moderationResult['reason'] ?? 'Message inapproprié détecté.';
            error_log("❌ Message bloqué par la modération : " . $error_msg);
            if (!empty($mediaUrl) && file_exists(__DIR__ . '/' . $mediaUrl)) {
                unlink(__DIR__ . '/' . $mediaUrl);
            }
            $_SESSION['post_error'] = $error_msg; // Réutiliser post_error de session
            header('Location: user_page.php?with=' . urlencode($recipientUsername));
            exit();
        }

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
?>