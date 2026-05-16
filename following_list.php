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

$stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
$stmt->bind_param('s', $_SESSION['email']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Utilisateur introuvable']);
    exit();
}

$followerId = $user['id'];

$query = $conn->prepare(
    "SELECT u.username, u.profile_pic
     FROM follows f
     JOIN users u ON f.followed_id = u.id
     WHERE f.follower_id = ?"
);
$query->bind_param('i', $followerId);
$query->execute();
$result = $query->get_result();

$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = [
        'username' => $row['username'],
        'profile_pic' => $row['profile_pic'] ?: 'default.png'
    ];
}

echo json_encode(['success' => true, 'users' => $users]);
?>