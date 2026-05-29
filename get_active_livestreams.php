<?php
session_start();
require_once 'config.php';
header('Content-Type: application/json');

$result = $conn->query(
    "SELECT l.user_id, u.username, l.title, l.start_time
     FROM livestreams l
     JOIN users u ON l.user_id = u.id
     JOIN (
         SELECT user_id, MAX(id) AS latest_id
         FROM livestreams
         WHERE is_active = 1
         GROUP BY user_id
     ) active_lives ON active_lives.user_id = l.user_id AND active_lives.latest_id = l.id
     ORDER BY l.start_time DESC"
);

if (!$result) {
    echo json_encode([
        'success' => false,
        'error' => 'Impossible de récupérer les livestreams actifs',
        'details' => $conn->error
    ]);
    exit;
}

$livestreams = [];
while ($row = $result->fetch_assoc()) {
    $livestreams[] = [
        'user_id' => (int)$row['user_id'],
        'username' => $row['username'],
        'title' => $row['title'],
        'start_time' => $row['start_time']
    ];
}

echo json_encode([
    'success' => true,
    'livestreams' => $livestreams
]);
