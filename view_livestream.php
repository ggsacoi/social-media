<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['email'])) {
    header('Location: index.php');
    exit();
}

$email = $_SESSION['email'];
$stmt = $conn->prepare('SELECT id, username FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$userResult = $stmt->get_result();
if ($userResult->num_rows === 0) {
    header('Location: index.php');
    exit();
}
$currentUser = $userResult->fetch_assoc();
$currentUserId = $currentUser['id'];
$currentUserName = $currentUser['username'];

$targetUsername = isset($_GET['u']) ? trim($_GET['u']) : '';
$targetUserId = null;
$targetDisplayName = '';
if ($targetUsername !== '') {
    $stmt = $conn->prepare('SELECT id, username FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $targetUsername);
    $stmt->execute();
    $targetResult = $stmt->get_result();
    if ($targetResult->num_rows > 0) {
        $targetUser = $targetResult->fetch_assoc();
        $targetUserId = $targetUser['id'];
        $targetDisplayName = $targetUser['username'];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voir le livestram de <?php echo htmlspecialchars($targetDisplayName ?: 'utilisateur'); ?></title>
    <style>
        body { margin: 0; background: #111; color: #fff; font-family: Arial, sans-serif; }
        .viewer-page { min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px; }
        .viewer-box { width: min(100%, 960px); max-width: 100%; }
        #liveVideo { width: 100%; max-height: 80vh; background: #000; border-radius: 12px; }
        .status { margin-top: 14px; font-size: 1rem; color: #ddd; }
        .top-bar { margin-bottom: 18px; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .top-bar h1 { margin: 0; font-size: 1.3rem; }
        .top-bar a { color: #fff; text-decoration: none; background: #e53935; padding: 10px 14px; border-radius: 8px; }
    </style>
</head>
<body>
    <div class="viewer-page">
        <div class="viewer-box">
            <div class="top-bar">
                <h1>Live de <?php echo htmlspecialchars($targetDisplayName ?: 'inconnu'); ?></h1>
                <a href="user_page.php">Retour</a>
            </div>
            <?php if (!$targetUserId): ?>
                <div class="status">Utilisateur introuvable ou URL invalide. Vérifiez le lien et reconnectez-vous.</div>
            <?php else: ?>
                <video id="liveVideo" autoplay playsinline muted controls></video>
                <div id="status" class="status">Initialisation du lecteur P2P HLS...</div>
                <script>
                    window.currentUserId = <?php echo json_encode($currentUserId); ?>;
                    window.currentUserName = <?php echo json_encode($currentUserName); ?>;
                    window.targetBroadcasterUserId = <?php echo json_encode($targetUserId); ?>;
                </script>
                <script src="//localhost:3000/socket.io/socket.io.js"></script>
                <script src="https://cdn.jsdelivr.net/npm/hls.js@1.2.9/dist/hls.min.js"></script>
                <script src="webrtc-client.js?v=22"></script>
                <script>
                    const statusEl = document.getElementById('status');
                    const videoEl = document.getElementById('liveVideo');

                    function updateStatus(message) {
                        if (statusEl) {
                            statusEl.textContent = message;
                        }
                        console.log('[VIEWER]', message);
                    }

                    async function initViewer() {
                        if (!window.WebRTCClient) {
                            updateStatus('Erreur: WebRTCClient non initialisé.');
                            return;
                        }
                        try {
                            updateStatus('Connexion au serveur de signalisation...');
                            await window.WebRTCClient.connect();
                            updateStatus('Connecté. Demande de flux au diffuseur...');

                            await window.WebRTCClient.startViewer(
                                window.currentUserId || 0,
                                window.targetBroadcasterUserId,
                                videoEl
                            );

                            updateStatus('En attente du flux du diffuseur...');

                            const timeout = setTimeout(() => {
                                if (!videoEl.srcObject) {
                                    updateStatus('Aucun flux reçu. Le diffuseur doit démarrer le live et le serveur doit être en ligne.');
                                }
                            }, 15000);

                            videoEl.addEventListener('playing', () => {
                                clearTimeout(timeout);
                                updateStatus('Flux reçu. Appuyez sur le bouton de lecture si nécessaire.');
                                videoEl.muted = false;
                            }, { once: true });
                        } catch (error) {
                            console.error('[VIEWER] Erreur initViewer:', error);
                            updateStatus('Erreur WebRTC: ' + (error.message || error));
                        }
                    }

                    document.addEventListener('DOMContentLoaded', initViewer);
                </script>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
