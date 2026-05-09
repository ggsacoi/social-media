<?php
session_start();
require_once 'config.php';
header('Content-Type: application/json');

if(!isset($_SESSION['email'])) {
    echo json_encode([]);
    exit();
}

$email = $_SESSION['email'];
$stmt = $conn->prepare('SELECT username FROM users WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$currentUsername = $user['username'];

$query = trim($_GET['q'] ?? '');

if (strlen($query) < 1) {
    echo json_encode([]);
    exit();
}

$searchQuery = '%' . $query . '%';
$stmt = $conn->prepare('SELECT username FROM users WHERE username LIKE ? AND username != ? LIMIT 4');
$stmt->bind_param('ss', $searchQuery, $currentUsername);
$stmt->execute();
$result = $stmt->get_result();

$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row['username'];
}

echo json_encode($users);
?>