<?php
// ajax_handlers.php - Gestion des requêtes AJAX

// AJAX pour charger les notifications de messages non lus
if (isset($_GET['unread_notifications'])) {
    header('Content-Type: application/json');
    $stmt = $conn->prepare("SELECT u.username, COUNT(*) AS unread_count FROM messages m JOIN users u ON m.id_expediteur = u.id WHERE m.id_destinataire = ? AND m.lu = 0 GROUP BY u.username ORDER BY unread_count DESC");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = [];
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $users[] = ['username' => $row['username'], 'count' => (int)$row['unread_count']];
        $total += (int)$row['unread_count'];
    }
    echo json_encode([
        'success' => true,
        'count' => $total,
        'users' => $users,
        'csrf_token' => $_SESSION['csrf_token'] ?? ''
    ]);
    exit;
}

// AJAX pour charger plus de commentaires
if (isset($_GET['ajax_comments'])) {
    $cPostId = (int)$_GET['post_id'];
    $all = isset($_GET['all']);

    if ($all) {
        $stmtCommentsAjax = $conn->prepare("SELECT c.*, u.username, u.profile_pic FROM comments c JOIN users u ON c.user_id = u.id WHERE c.post_id = ? ORDER BY RAND($commentSeed + c.id)");
        $stmtCommentsAjax->bind_param('i', $cPostId);
    } else {
        $cPage = (int)$_GET['c_page'];
        $cLimit = 5;
        // Page 1 affichait 1 seul commentaire. Page 2 commence à l'offset 1.
        $cOffset = 1 + (($cPage - 2) * $cLimit);
        $stmtCommentsAjax = $conn->prepare("SELECT c.*, u.username, u.profile_pic FROM comments c JOIN users u ON c.user_id = u.id WHERE c.post_id = ? ORDER BY RAND($commentSeed + c.id) LIMIT ? OFFSET ?");
        $stmtCommentsAjax->bind_param('iii', $cPostId, $cLimit, $cOffset);
    }

    $stmtCommentsAjax->execute();
    $ajaxComments = $stmtCommentsAjax->get_result();
    while($comment = $ajaxComments->fetch_assoc()): ?>
        <div class="comment-item" data-comment-username="<?php echo htmlspecialchars($comment['username'], ENT_QUOTES, 'UTF-8'); ?>" data-comment-content="<?php echo htmlspecialchars($comment['contenu'], ENT_QUOTES, 'UTF-8'); ?>">
            <div class="comment-character">
                <span class="people" style="background-image: url('<?php echo htmlspecialchars($comment['profile_pic']); ?>'); background-size: cover;">
                    <?php if(empty($comment['profile_pic'])): ?><i class="fa-solid fa-user"></i><?php endif; ?>
                </span>
                <h4><?php echo htmlspecialchars($comment['username']); ?></h4>
            </div>
            <p><?php echo htmlspecialchars($comment['contenu']); ?></p>
            <?php if($comment['media_url']): ?>
                <?php if($comment['media_type'] === 'image'): ?>
                    <img class="comment-subject" src="<?php echo htmlspecialchars($comment['media_url']); ?>" alt="Comment Image">
                <?php elseif($comment['media_type'] === 'video'): ?>
                    <video controls class="comment-subject" style="max-width: 100%;"><source src="<?php echo htmlspecialchars($comment['media_url']); ?>"></video>
                <?php elseif($comment['media_type'] === 'audio'): ?>
                    <div class="audio-visualizer" data-src="<?php echo htmlspecialchars($comment['media_url']); ?>">
                        <button type="button" class="audio-play-button">▶ Play</button>
                        <canvas></canvas>
                        <span class="audio-status">Audio</span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endwhile;
    $stmtCommentsAjax->close();
    exit;
}

// AJAX pour charger plus d'utilisateurs dans la barre latérale
if (isset($_GET['ajax_users'])) {
    $uLimit = 9;
    $uPage = isset($_GET['user_page']) ? (int)$_GET['user_page'] : 2;
    $uOffset = ($uPage - 1) * $uLimit;
    $commentSeed = isset($_GET['seed']) && $_GET['seed'] !== '' ? (int)$_GET['seed'] : (int)($_SESSION['comment_seed'] ?? 1);
    $sUsers = $conn->prepare("SELECT id, username, profile_pic FROM users ORDER BY RAND(?) LIMIT ? OFFSET ?");
    $sUsers->bind_param('iii', $commentSeed, $uLimit, $uOffset);
    $sUsers->execute();
    $sUsers = $sUsers->get_result();
    while($su = $sUsers->fetch_assoc()): ?>
        <div class="eloko" data-user-id="<?php echo htmlspecialchars($su['id'], ENT_QUOTES, 'UTF-8'); ?>">
            <a href="profil.php?u=<?php echo urlencode($su['username']); ?>" class="avatar-link">
                <span class="people" style="<?php echo !empty($su['profile_pic']) ? "background-image: url('" . htmlspecialchars($su['profile_pic']) . "'); background-size: cover;" : ''; ?>">
                    <?php if(empty($su['profile_pic'])): ?><i class="fa-solid fa-user"></i><?php endif; ?>
                </span>
            </a>
            <a href="profil.php?u=<?php echo urlencode($su['username']); ?>" class="name-link">
                <h4><?php echo htmlspecialchars($su['username']); ?></h4>
            </a>
            <span style="color:#666;font-size:0.9rem;">&nbsp;</span>
            <a href="user_page.php?with=<?php echo urlencode($su['username']); ?>" class="chat-icon-btn" title="Envoyer un message">
                <i class="fa-regular fa-comment-dots"></i>
            </a>
        </div>
    <?php endwhile;
    exit;
}

// --- LOGIQUE DE PAGINATION ---
$limit = 20; 
$currentPage = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;
$offset = ($currentPage - 1) * $limit;

$totalPostsRes = $conn->prepare("SELECT COUNT(*) as total FROM posts");
$totalPostsRes->execute();
$totalPostsRes = $totalPostsRes->get_result();
$totalPosts = $totalPostsRes->fetch_assoc()['total'];
$totalPages = ceil($totalPosts / $limit);

$allPosts = $conn->prepare("SELECT p.*, u.username, u.profile_pic FROM posts p JOIN users u ON p.user_id = u.id ORDER BY p.date_publication DESC LIMIT ? OFFSET ?");
$allPosts->bind_param('ii', $limit, $offset);
$allPosts->execute();
$allPosts = $allPosts->get_result();

if (isset($_GET['ajax'])) {
    while($post = $allPosts->fetch_assoc()): ?>
        <div class="post-item" id="post-<?php echo $post['id']; ?>">
            <div class="top">
                <div class="character">
                    <span class="people" style="background-image: url('<?php echo htmlspecialchars($post['profile_pic']); ?>'); background-size: cover;">
                        <?php if(empty($post['profile_pic'])): ?><i class="fa-solid fa-user"></i><?php endif; ?>
                    </span> 
                    <h3><?php echo htmlspecialchars($post['username']); ?></h3>
                </div>
                <h3 style="display: flex; align-items: center; justify-content: center;" ><?php echo htmlspecialchars($post['legende']); ?></h3>
            </div>

            <?php if($post['media_url']): ?>
                <?php if($post['media_type'] === 'image'): ?>
                    <img class="subject" src="<?php echo htmlspecialchars($post['media_url']); ?>" alt="Post Image">
                <?php elseif($post['media_type'] === 'video'): ?>
                    <video controls class="subject" style="max-width: 100%;"><source src="<?php echo htmlspecialchars($post['media_url']); ?>"></video>
                <?php elseif($post['media_type'] === 'audio'): ?>
                    <div class="audio-visualizer" data-src="<?php echo htmlspecialchars($post['media_url']); ?>">
                        <button type="button" class="audio-play-button">▶ Play</button>
                        <canvas></canvas>
                        <span class="audio-status">Audio</span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Comment Form -->
            <form method="POST" action="user_page.php" enctype="multipart/form-data" class="comment-form">
                <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                <div class="wrote" style="margin-top: 20px;">
                    <input class="wrotecomment" type="text" name="comment_content" placeholder="Ajouter un commentaire...">
                    <button class="sendcomment" type="submit" name="submit_comment">Envoyer</button>
                    <button type="button" class="sendMediaComment" data-post-id="<?php echo $post['id']; ?>"><i class="fa-regular fa-image"></i></button>
                    <button type="button" class="micButtonComment" data-post-id="<?php echo $post['id']; ?>"><i class="fa-solid fa-microphone"></i></button>
                    <input type="file" name="comment_media_file" class="comment-media-input" data-post-id="<?php echo $post['id']; ?>" style="display:none;" accept="image/*,video/*,audio/*">
                    <input type="hidden" class="recordedCommentAudioData" name="recorded_comment_audio_data" data-post-id="<?php echo $post['id']; ?>">
                </div>
                <span class="recordStatusComment" data-post-id="<?php echo $post['id']; ?>"></span>
                <div class="fileNameDisplayComment" data-post-id="<?php echo $post['id']; ?>" style="display:none;"></div>
            </form>

            <!-- Display Comments -->
            <div class="comments-section" id="comments-section-<?php echo $post['id']; ?>">
                <?php
                $countStmt = $conn->prepare('SELECT COUNT(*) as total FROM comments WHERE post_id = ?');
                $countStmt->bind_param('i', $post['id']);
                $countStmt->execute();
                $totalComments = $countStmt->get_result()->fetch_assoc()['total'];
                $totalCommentPages = ($totalComments > 1) ? ceil(($totalComments - 1) / 5) + 1 : 1;

                $stmtComments = $conn->prepare("SELECT c.*, u.username, u.profile_pic FROM comments c JOIN users u ON c.user_id = u.id WHERE c.post_id = ? ORDER BY RAND($commentSeed + c.id) LIMIT 1");
                $stmtComments->bind_param('i', $post['id']);
                $stmtComments->execute();
                $postComments = $stmtComments->get_result();
                while($comment = $postComments->fetch_assoc()):
                ?>
                    <div class="comment-item">
                        <div class="comment-character">
                            <span class="people" style="background-image: url('<?php echo htmlspecialchars($comment['profile_pic']); ?>'); background-size: cover;">
                                <?php if(empty($comment['profile_pic'])): ?><i class="fa-solid fa-user"></i><?php endif; ?>
                            </span>
                            <h4><?php echo htmlspecialchars($comment['username']); ?></h4>
                        </div>
                        <p><?php echo htmlspecialchars($comment['contenu']); ?></p>
                        <?php if($comment['media_url']): ?>
                            <?php if($comment['media_type'] === 'image'): ?>
                                <img class="comment-subject" src="<?php echo htmlspecialchars($comment['media_url']); ?>" alt="Comment Image">
                            <?php elseif($comment['media_type'] === 'video'): ?>
                                <video controls class="comment-subject" style="max-width: 100%;"><source src="<?php echo htmlspecialchars($comment['media_url']); ?>"></video>
                            <?php elseif($comment['media_type'] === 'audio'): ?>
                                <div class="audio-visualizer" data-src="<?php echo htmlspecialchars($comment['media_url']); ?>">
                                    <button type="button" class="audio-play-button">▶ Play</button>
                                    <canvas></canvas>
                                    <span class="audio-status">Audio</span>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endwhile;
                $stmtComments->close();
                ?>
            </div>
            <?php if ($totalCommentPages > 1): ?>
                <button class="load-more-comments-btn" data-post-id="<?php echo $post['id']; ?>" data-current-page="1" data-total-pages="<?php echo $totalCommentPages; ?>" style="background:none; border:none; color:#1a73e8; cursor:pointer; font-size:0.85rem; padding:10px 0;">
                    Afficher plus de commentaires
                </button>
            <?php endif; ?>
        </div>
    <?php endwhile;
    exit;
}
?>