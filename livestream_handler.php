<?php
session_start();
require_once 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF invalide']);
    exit;
}

$action = $_POST['action'] ?? '';
if (empty($action)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Action manquante']);
    exit;
}

if (!isset($_SESSION['email'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Utilisateur non authentifié']);
    exit;
}

$email = $_SESSION['email'];
$stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$userResult = $stmt->get_result();
if ($userResult->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Utilisateur introuvable']);
    exit;
}
$user = $userResult->fetch_assoc();
$userId = (int)$user['id'];

if ($action === 'start') {
    $title = trim($_POST['title'] ?? 'Livestream');

    // Fermer toute session live active existante pour cet utilisateur avant d'en créer une nouvelle.
    $closeStmt = $conn->prepare('UPDATE livestreams SET end_time = NOW(), is_active = 0 WHERE user_id = ? AND is_active = 1');
    if ($closeStmt) {
        $closeStmt->bind_param('i', $userId);
        $closeStmt->execute();
    }

    $stmt = $conn->prepare('INSERT INTO livestreams (user_id, title, start_time, is_active) VALUES (?, ?, NOW(), 1)');
    $stmt->bind_param('is', $userId, $title);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'livestream_id' => $stmt->insert_id]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Impossible de démarrer le livestream', 'details' => $conn->error]);
    exit;
}

if ($action === 'stop') {
    $livestreamId = isset($_POST['livestream_id']) ? (int)$_POST['livestream_id'] : 0;
    if ($livestreamId <= 0) {
        // Si l'ID n'est pas fourni, arrêter toute session active de l'utilisateur.
        $stmt = $conn->prepare('UPDATE livestreams SET end_time = NOW(), is_active = 0 WHERE user_id = ? AND is_active = 1');
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
                exit;
            }
        }
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID de livestream invalide ou accès refusé']);
        exit;
    }
    $stmt = $conn->prepare('UPDATE livestreams SET end_time = NOW(), is_active = 0 WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $livestreamId, $userId);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Impossible d’arrêter le livestream', 'details' => $conn->error]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Action inconnue']);
