<?php
session_start();
require_once 'config.php';

// Assurer que la table follows existe
$conn->query("CREATE TABLE IF NOT EXISTS `follows` (
    `follower_id` INT(10) UNSIGNED NOT NULL,
    `followed_id` INT(10) UNSIGNED NOT NULL,
    PRIMARY KEY (`follower_id`, `followed_id`),
    FOREIGN KEY (`follower_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`followed_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;");

header('Content-Type: application/json');

if (!isset($_SESSION['email'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';
$profileId = (int)($data['profileId'] ?? 0);
$token = $data['csrf_token'] ?? '';

// Validation du token CSRF
if (!validateCsrfToken($token)) {
    echo json_encode(['success' => false, 'message' => 'Erreur CSRF']);
    exit();
}

if (!$profileId || !in_array($action, ['follow', 'unfollow'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit();
}

// Récupérer l'ID du follower
$checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
$checkStmt->bind_param("s", $_SESSION['email']);
$checkStmt->execute();
$followerId = $checkStmt->get_result()->fetch_assoc()['id'];

if ($action === 'follow') {
    // Créer la relation de suivi si elle n'existe pas encore
    $stmt = $conn->prepare("INSERT IGNORE INTO follows (follower_id, followed_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $followerId, $profileId);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        $updateStmt = $conn->prepare("UPDATE users SET nb_following = nb_following + 1 WHERE id = ?");
        $updateStmt->bind_param("i", $followerId);
        $updateStmt->execute();
    }

} elseif ($action === 'unfollow') {
    // Supprimer la relation de suivi
    $stmt = $conn->prepare("DELETE FROM follows WHERE follower_id = ? AND followed_id = ?");
    $stmt->bind_param("ii", $followerId, $profileId);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        $updateStmt = $conn->prepare("UPDATE users SET nb_following = nb_following - 1 WHERE id = ? AND nb_following > 0");
        $updateStmt->bind_param("i", $followerId);
        $updateStmt->execute();
    }
}

echo json_encode(['success' => true]);
?>