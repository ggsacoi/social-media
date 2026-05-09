<?php
session_start();
require_once 'config.php';

if(!isset($_SESSION['email'])) {
    header('Location: index.php');
    exit();
}

$email = $_SESSION['email'];

$stmt = $conn->prepare('SELECT id, username FROM users WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows === 0) {
    header('Location: index.php');
    exit();
}

$user = $result->fetch_assoc();
$senderId = $user['id'];
$senderName = $user['username'];

$success = '';
$error = '';

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipientUsername = trim($_POST['username_destinataire'] ?? '');
    $contenu = trim($_POST['contenu'] ?? '');

    if($recipientUsername === '' || $contenu === '') {
        $error = 'Veuillez renseigner le username du destinataire et le message.';
    } else if($recipientUsername === $senderName) {
        $error = 'Vous ne pouvez pas vous envoyer un message à vous-même.';
    } else {
        $stmt = $conn->prepare('SELECT id, username FROM users WHERE username = ?');
        $stmt->bind_param('s', $recipientUsername);
        $stmt->execute();
        $destResult = $stmt->get_result();

        if($destResult->num_rows === 0) {
            $error = 'Utilisateur "' . htmlspecialchars($recipientUsername, ENT_QUOTES, 'UTF-8') . '" introuvable.';
        } else {
            $recipient = $destResult->fetch_assoc();
            $recipientId = $recipient['id'];
            $dateEnvoi = date('Y-m-d H:i:s');
            $stmt = $conn->prepare('INSERT INTO messages (id_expediteur, id_destinataire, contenu, date_envoi, lu) VALUES (?, ?, ?, ?, 0)');
            $stmt->bind_param('iiss', $senderId, $recipientId, $contenu, $dateEnvoi);

            if($stmt->execute()) {
                header('Location: user_page.php?with=' . urlencode($recipientUsername));
                exit();
            } else {
                $error = 'Erreur lors de l\'envoi du message. Réessayez plus tard.';
            }
        }
    }
}

$users = $conn->query('SELECT id, username, email FROM users ORDER BY id ASC');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document - Envoi de message</title>
    <link rel="stylesheet" href="home.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        .document-container { max-width: 900px; margin: 40px auto; padding: 20px; background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .document-container h1 { margin-bottom: 24px; font-size: 28px; }
        .message-form { display: grid; gap: 16px; }
        .message-form input, .message-form textarea { width: 100%; padding: 12px 14px; border: 1px solid #ccc; border-radius: 10px; font-size: 16px; }
        .message-form button { width: fit-content; padding: 10px 20px; border: none; border-radius: 10px; background: #1a73e8; color: #fff; cursor: pointer; }
        .message-form button:hover { background: #1666c1; }
        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; }
        .alert-success { background: #e6f4ea; color: #1b5e20; }
        .alert-error { background: #fdecea; color: #b00020; }
        .user-list { margin-top: 30px; }
        .user-list table { width: 100%; border-collapse: collapse; }
        .user-list th, .user-list td { padding: 12px 10px; border-bottom: 1px solid #ddd; text-align: left; }
        .user-list th { background: #f5f7fb; }
        .search-container { position: relative; width: 100%; }
        .search-results { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #ccc; border-top: none; border-radius: 0 0 10px 10px; max-height: 200px; overflow-y: auto; display: none; z-index: 100; }
        .search-results.active { display: block; }
        .search-result-item { padding: 12px 14px; cursor: pointer; border-bottom: 1px solid #eee; }
        .search-result-item:hover { background-color: #f0f0f0; }
        .search-result-item:last-child { border-bottom: none; }
    </style>
</head>
<body>
    <div class="document-container">
        <h1>Envoyer un message</h1>
        <p>Connecté en tant que <strong><?php echo htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8'); ?></strong> (ID <?php echo htmlspecialchars($senderId, ENT_QUOTES, 'UTF-8'); ?>)</p>

        <?php if($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form class="message-form" method="post" action="document.php">
            <label for="username_destinataire">Envoyer à (username)</label>
            <div class="search-container">
                <input type="text" name="username_destinataire" id="username_destinataire" placeholder="Entrez le username du destinataire" required>
                <div class="search-results" id="searchResults"></div>
            </div>

            <label for="contenu">Message</label>
            <textarea name="contenu" id="contenu" rows="5" required><?php echo isset($contenu) ? htmlspecialchars($contenu, ENT_QUOTES, 'UTF-8') : ''; ?></textarea>

            <button type="submit">Envoyer</button>
        </form>

        <script>
            const inputField = document.getElementById('username_destinataire');
            const resultsContainer = document.getElementById('searchResults');

            inputField.addEventListener('input', function() {
                const query = this.value.trim();
                if (query.length < 1) {
                    resultsContainer.classList.remove('active');
                    return;
                }

                fetch('search_users.php?q=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        resultsContainer.innerHTML = '';
                        if (data.length > 0) {
                            data.forEach(username => {
                                const item = document.createElement('div');
                                item.className = 'search-result-item';
                                item.textContent = username;
                                item.addEventListener('click', function() {
                                    inputField.value = username;
                                    resultsContainer.classList.remove('active');
                                });
                                resultsContainer.appendChild(item);
                            });
                            resultsContainer.classList.add('active');
                        } else {
                            resultsContainer.classList.remove('active');
                        }
                    });
            });

            document.addEventListener('click', function(e) {
                if (e.target !== inputField && e.target !== resultsContainer) {
                    resultsContainer.classList.remove('active');
                }
            });
        </script>
</body>
</html>
