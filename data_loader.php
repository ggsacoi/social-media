<?php
// data_loader.php - Chargement des données pour la page

// Récupération de tous les utilisateurs pour la barre latérale (bomoto)
$userLimit = 9;
$totalUsersRes = $conn->query("SELECT COUNT(*) as total FROM users");
$totalUsersCount = $totalUsersRes->fetch_assoc()['total'];
$totalUserPages = ceil($totalUsersCount / $userLimit);
$sidebarUsers = $conn->query("SELECT username, profile_pic FROM users ORDER BY username ASC LIMIT $userLimit");

$withUsername = trim($_GET['with'] ?? '');
$withId = null;
$conversationSelected = false;
if ($withUsername !== '') {
    $withStmt = $conn->prepare('SELECT id FROM users WHERE username = ?');
    if (!$withStmt) {
        die("Erreur de préparation de la requête interlocuteur: " . $conn->error);
    }
    $withStmt->bind_param('s', $withUsername);
    if (!$withStmt->execute()) {
        die("Erreur d'exécution de la requête interlocuteur: " . $conn->error);
    }
    $withResult = $withStmt->get_result();
    if ($withResult->num_rows > 0) {
        $with = $withResult->fetch_assoc();
        $withId = $with['id'];
        $conversationSelected = true;
    }
}

if ($conversationSelected) {
    $updateReadStmt = $conn->prepare('UPDATE messages SET lu = 1 WHERE id_destinataire = ? AND id_expediteur = ? AND lu = 0');
    if ($updateReadStmt) {
        $updateReadStmt->bind_param('ii', $userId, $withId);
        $updateReadStmt->execute();
    }

    $msgStmt = $conn->prepare('SELECT m.id, m.contenu, m.media_url, m.media_type, m.date_envoi, m.lu, u.username AS interlocuteur, IF(m.id_destinataire = ?, "reçu", "envoyé") AS type FROM messages m JOIN users u ON IF(m.id_destinataire = ?, m.id_expediteur, m.id_destinataire) = u.id WHERE (m.id_destinataire = ? AND m.id_expediteur = ?) OR (m.id_destinataire = ? AND m.id_expediteur = ?) ORDER BY m.date_envoi ASC');
    if (!$msgStmt) {
        die("Erreur de préparation de la requête messages: " . $conn->error);
    }
    $msgStmt->bind_param('iiiiii', $userId, $userId, $userId, $withId, $withId, $userId);
    if (!$msgStmt->execute()) {
        die("Erreur d'exécution de la requête messages: " . $conn->error);
    }
    $messages = $msgStmt->get_result();
} else {
    $messages = false;
}

function getMessageSeenLabel($type, $lu) {
    if ($type !== 'envoyé') {
        return '';
    }
    return $lu ? 'Vu' : 'Non lu';
}

?>