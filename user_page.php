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

$error_msg = '';

// --- LOGIQUE D'ENVOI (Issue de document.php) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                $uploadDir = 'uploads/messages/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                    error_log("Created directory: $uploadDir"); // Debug log
                }
                $newFilename = uniqid() . '.' . $audioExt;
                $targetPath = $uploadDir . $newFilename;
                error_log("Target path: $targetPath"); // Debug log
                
                if (file_put_contents($targetPath, $audioContent) !== false) {
                    $mediaUrl = $targetPath;
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
            $uploadDir = 'uploads/messages/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $newFilename = uniqid() . '.' . $ext;
            $targetPath = $uploadDir . $newFilename;
            
            if(move_uploaded_file($_FILES['media_file']['tmp_name'], $targetPath)) {
                $mediaUrl = $targetPath;
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
    <link rel="stylesheet" href="home.css?v=2">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        .search-container { position: relative; }
        .search-results { 
            position: absolute; top: 100%; left: 0; right: 0; 
            background: white; border: 1px solid #ccc; border-radius: 0 0 8px 8px; 
            max-height: 200px; overflow-y: auto; display: none; z-index: 1000; 
        }
        .search-results.active { display: block; }
        .search-result-item { padding: 10px; cursor: pointer; border-bottom: 1px solid #eee; color: #333; }
        .search-result-item:hover { background-color: #f0f0f0; }

        .audio-visualizer {
            margin-top: 10px;
            background: transparent;
            border-radius: 12px;
            color: #fff;
            display: grid;
            gap: 10px;
            width: 100%;
        }
        .audio-visualizer canvas {
            width: 200px;
            height: 60px;
            border-radius: 10px;
            background: #1f1f1f;
            display: block;
            margin-left: -20px;
        }
        .audio-play-button {
            border: none;
            background: linear-gradient(135deg, #4d9cff, #1e6cff);
            color: white;
            padding: 10px 14px;
            border-radius: 999px;
            cursor: pointer;
            width: fit-content;
            font-size: 1rem;
            margin-left: -20px;
        }
        .audio-play-button:hover {
            opacity: 0.9;
        }
        .audio-status {
            font-size: 0.85rem;
            color: #d0d0d0;
        }

        /* Styles pour l'affichage du fichier dans le textarea */
        .textarea-wrapper { position: relative; width: 100%; margin-top: 15px; }
        #fileNameDisplay { position: absolute; top: 20px; left: 10px; z-index: 10; background: #e7f3ff; padding: 4px 8px; border-radius: 5px; border: 1px solid #1a73e8; pointer-events: none; }
        .conv textarea.with-file { padding-top: 45px; }

        /* Style pour les icônes d'action */
        .fa-image, .fa-microphone, .fa-stop {
            color: white;
        }

        /* Bulle transparente uniquement pour les messages audio */
        .bulle.audio-msg {
            background: transparent !important;
            box-shadow: none !important;
            border: none !important;
        }
    </style>
</head>
<body>
    <section><button style="color: black;" onclick="window.location.href='logout.php'">logout</button></section>
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
                        <span id="fileNameDisplay" style="font-size: 0.85rem; color: #1a73e8; font-weight: bold; vertical-align: middle; display: none;"></span>
                        <textarea name="contenu" id="contenu" rows="5" placeholder="Votre message..."></textarea>
                    </div>
                    <div>
                        <button type="submit">Envoyer</button>
                        <input type="file" name="media_file" id="mediaInput" style="display:none;" accept="image/*,video/*,audio/*">
                        <input type="hidden" name="recorded_audio_data" id="recordedAudioData">
                        <button type="button" class="media" style="color: white;" onclick="openMediaFile();"><i class="fa-regular fa-image"></i></button>
                        <button type="button" id="micButton" style="color: white;"><i class="fa-solid fa-microphone"></i></button>
                        <span id="recordStatus" style="margin-left: 10px; font-size: 0.95rem; color: #1a73e8;"></span>
                    </div>
                </div>
                </form>
            </article>
        </main>
        <script src="https://kit.fontawesome.com/d28f9485ed.js" crossorigin="anonymous"></script>
        <script src="user_page.js"></script> 
        </script>
        </body>
</html>