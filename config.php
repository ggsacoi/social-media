<?php
// Détecter si le serveur a rejeté la requête POST parce qu'elle dépasse post_max_size
$isJsonRequest = isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isJsonRequest && empty($_POST) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
    die('Erreur : La taille des données envoyées dépasse la limite autorisée par le serveur (post_max_size).');
}

    // Détection automatique de l'environnement (Local vs Serveur)
    if (isset($_SERVER['HTTP_HOST']) && ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1')) {
        // Configuration pour votre ordinateur (XAMPP local)
        $host = "localhost";
        $user = "root";
        $password = "";
        $database = "systeme-relationnel-humain";
    } else {
        // Configuration pour votre serveur Hostinger (En ligne)
        $host = "localhost";
        $user = "lanceur-mer-base";
        $password = "hmb16e0xoqmaLeX3hTrm";
        $database = "systeme-relationnel-humain";
    }

$conn = new mysqli($host, $user, $password, $database);

if($conn->connect_error) {
    die("Connection failed: ". $conn->connect_error);
}

// Valeur par défaut du secret de signalisation, utilisée uniquement si aucun env n'est configuré.
define('SIGNALING_SECRET_DEFAULT', 'CHANGE_THIS_DEFAULT_SIGNALING_SECRET');

// ===== PROTECTION CSRF =====
// Générer un token CSRF s'il n'existe pas
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            error_log('✅ CSRF Token généré: ' . substr($_SESSION['csrf_token'], 0, 20) . '...');
        } catch (Exception $e) {
            error_log('❌ Erreur génération CSRF: ' . $e->getMessage());
            // Fallback en cas d'erreur
            $_SESSION['csrf_token'] = md5(time() . mt_rand());
            error_log('⚠️ CSRF Token (fallback): ' . $_SESSION['csrf_token']);
        }
    }
    return $_SESSION['csrf_token'];
}

// Valider le token CSRF
function validateCsrfToken($token) {
    if (!isset($_SESSION['csrf_token'])) {
        error_log('❌ validateCsrfToken: SESSION token not set');
        return false;
    }
    if (empty($token)) {
        error_log('❌ validateCsrfToken: POST token is empty');
        return false;
    }
    $isValid = hash_equals($_SESSION['csrf_token'], $token);
    error_log('validateCsrfToken: ' . ($isValid ? '✅ VALID' : '❌ INVALID'));
    error_log('  SESSION: ' . substr($_SESSION['csrf_token'], 0, 20) . '...');
    error_log('  POST: ' . substr($token, 0, 20) . '...');
    return $isValid;
}

// Récupérer le token pour les formulaires
function getCsrfToken() {
    return generateCsrfToken();
}

function getSignalingSecret() {
    $secret = getenv('SIGNALING_SECRET');
    if (!$secret) {
        error_log('⚠️ SIGNALING_SECRET non défini dans l\'environnement ; utilisation du placeholder par défaut. Configurez SIGNALING_SECRET dans PHP et Node pour qu\'ils correspondent.');
        $secret = SIGNALING_SECRET_DEFAULT;
    }
    return $secret;
}

function getSignalingToken($userId) {
    $payload = json_encode([
        'userId' => (string)$userId,
        'session' => session_id(),
        'ts' => time()
    ]);
    $signature = hash_hmac('sha256', $payload, getSignalingSecret());
    return base64_encode($payload) . '.' . $signature;
}

function validateSignalingToken($token, $expectedUserId = null) {
    if (empty($token)) {
        return false;
    }
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return false;
    }
    list($encodedPayload, $signature) = $parts;
    $payload = base64_decode($encodedPayload, true);
    if ($payload === false) {
        return false;
    }
    $expectedSignature = hash_hmac('sha256', $payload, getSignalingSecret());
    if (!hash_equals($expectedSignature, $signature)) {
        return false;
    }
    $data = json_decode($payload, true);
    if (!is_array($data) || empty($data['userId']) || empty($data['ts'])) {
        return false;
    }
    if ($expectedUserId !== null && (string)$data['userId'] !== (string)$expectedUserId) {
        return false;
    }
    if (time() - (int)$data['ts'] > 300) {
        error_log('⚠️ Signaling token expiré');
        return false;
    }
    return true;
}

require_once 'moderation.php';
?>