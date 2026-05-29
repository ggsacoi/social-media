<?php
session_start();
require_once 'config.php';

// Création automatique de la table follows si elle n'existe pas
$conn->query("CREATE TABLE IF NOT EXISTS `follows` (
    `follower_id` INT(10) UNSIGNED NOT NULL,
    `followed_id` INT(10) UNSIGNED NOT NULL,
    PRIMARY KEY (`follower_id`, `followed_id`),
    FOREIGN KEY (`follower_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`followed_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;");

// Redirection si l'utilisateur n'est pas connecté
if (!isset($_SESSION['email'])) {
    header("Location: index.php");
    exit();
}

// Récupérer l'ID de l'utilisateur connecté (nécessaire pour les mises à jour et les commentaires)
$currentLoggedEmail = $_SESSION['email'];
$checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
$checkStmt->bind_param("s", $currentLoggedEmail);
$checkStmt->execute();
$loggedInId = $checkStmt->get_result()->fetch_assoc()['id'];

// On détermine quel profil afficher (soi-même par défaut, ou via l'URL)
// Déplacé ici pour être accessible aux redirections
$profileUsername = isset($_GET['u']) && $_GET['u'] !== '' ? $_GET['u'] : $_SESSION['username'];

// Préparation du paramètre d'URL pour les redirections (uParam)
$uParam = (isset($_GET['u']) && $_GET['u'] !== '') ? '?u=' . urlencode($_GET['u']) : '';

// Initialisation d'une graine (seed) pour la randomisation stable des commentaires
// On la régénère à chaque chargement de page pour que l'ordre change à chaque visite
if (!isset($_GET['ajax_comments'])) {
    $_SESSION['comment_seed'] = rand(1, 999999);
}
$commentSeed = (int)$_SESSION['comment_seed'];

// Logique d'envoi de commentaire (Ajoutée pour traiter le formulaire sur cette page)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['submit_comment']) || isset($_GET['ajax_submit']))) {
    // Validation du token CSRF
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        die('Erreur de sécurité CSRF. Veuillez réessayer.');
    }
    
    $postId = $_POST['post_id'] ?? null;
    $commentContent = trim($_POST['comment_content'] ?? '');
    $commentMediaUrl = '';
    $commentMediaType = 'texte';

    if ($postId) {
        // Gestion audio (Microphone)
        if (!empty($_POST['recorded_comment_audio_data'])) {
            $recordedData = $_POST['recorded_comment_audio_data'];
            if (preg_match('/^data:audio\/([a-zA-Z0-9.+-]+);base64,(.*)$/', $recordedData, $matches)) {
                $audioContent = base64_decode($matches[2]);
                $uploadDir = __DIR__ . '/uploads/comments/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                $newFilename = 'comment_audio_' . uniqid() . '.webm';
                if (file_put_contents($uploadDir . $newFilename, $audioContent)) {
                    $commentMediaUrl = 'uploads/comments/' . $newFilename;
                    $commentMediaType = 'audio';
                }
            }
        }
        // Gestion média classique
        if ($commentMediaUrl === '' && isset($_FILES['comment_media_file']) && $_FILES['comment_media_file']['error'] === 0) {
            $ext = strtolower(pathinfo($_FILES['comment_media_file']['name'], PATHINFO_EXTENSION));
            $uploadDir = __DIR__ . '/uploads/comments/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $newFilename = 'comment_media_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['comment_media_file']['tmp_name'], $uploadDir . $newFilename)) {
                $commentMediaUrl = 'uploads/comments/' . $newFilename;
                $commentMediaType = in_array($ext, ['mp4', 'webm']) ? 'video' : (in_array($ext, ['mp3', 'wav']) ? 'audio' : 'image');
            }
        }

        if (!empty($commentContent) || !empty($commentMediaUrl)) {
            $stmtComment = $conn->prepare('INSERT INTO comments (post_id, user_id, contenu, media_url, media_type) VALUES (?, ?, ?, ?, ?)');
            $stmtComment->bind_param('iisss', $postId, $loggedInId, $commentContent, $commentMediaUrl, $commentMediaType);
            $stmtComment->execute();

            // Si c'est une requête AJAX, on renvoie le HTML du nouveau commentaire
            if (isset($_GET['ajax_submit'])) {
                $lastId = $conn->insert_id;
                $stmtNew = $conn->prepare("SELECT c.*, u.username, u.profile_pic FROM comments c JOIN users u ON c.user_id = u.id WHERE c.id = ?");
                $stmtNew->bind_param("i", $lastId);
                $stmtNew->execute();
                $comment = $stmtNew->get_result()->fetch_assoc();
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
                <?php
                exit;
            }
        }
        // Redirection vers le profil actuel avec ancre sur le post
        header("Location: profil.php" . $uParam . "#post-" . $postId);
        exit();
    }
}

// Logique de mise à jour du profil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    // Validation du token CSRF
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        die('Erreur de sécurité CSRF. Veuillez réessayer.');
    }
    
    $newNumero = trim($_POST['numero'] ?? '');
    $newEmail = trim($_POST['email'] ?? '');
    $newBio = trim($_POST['bio'] ?? '');

    $mediaPath = "";
    // Gestion de la nouvelle photo de profil
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'jfif'])) {
            $newFilename = $_SESSION['username'] . "_" . time() . "." . $ext;
            $uploadDir = 'uploads/avatars/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $uploadDir . $newFilename)) {
                $mediaPath = $uploadDir . $newFilename;
                $_SESSION['profile_pic'] = $mediaPath;
            }
        }
    }

    if ($mediaPath !== "") {
        $upStmt = $conn->prepare("UPDATE users SET numero = ?, email = ?, bio = ?, profile_pic = ? WHERE id = ?");
        $upStmt->bind_param("ssssi", $newNumero, $newEmail, $newBio, $mediaPath, $loggedInId);
    } else {
        $upStmt = $conn->prepare("UPDATE users SET numero = ?, email = ?, bio = ? WHERE id = ?");
        $upStmt->bind_param("sssi", $newNumero, $newEmail, $newBio, $loggedInId);
    }

    if ($upStmt->execute()) {
        $_SESSION['email'] = $newEmail; // Mettre à jour la session si l'email a changé
        header("Location: profil.php" . $uParam);
        exit();
    }
}

// Récupération des informations de l'utilisateur concerné
$stmt = $conn->prepare("SELECT id, username, profile_pic, numero, email, bio FROM users WHERE username = ?");
$stmt->bind_param("s", $profileUsername);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    die("Utilisateur introuvable.");
}

$profileUser = $res->fetch_assoc();
$profileId = $profileUser['id'];

// Vérifier si l'utilisateur connecté suit ce profil via la table follows
$isFollowing = false;
if ($loggedInId != $profileId) {
    $checkFollowStmt = $conn->prepare("SELECT COUNT(*) AS count FROM follows WHERE follower_id = ? AND followed_id = ?");
    $checkFollowStmt->bind_param("ii", $loggedInId, $profileId);
    $checkFollowStmt->execute();
    $isFollowing = $checkFollowStmt->get_result()->fetch_assoc()['count'] > 0;
}

// Statistiques : nombre de publications
$countStmt = $conn->prepare("SELECT COUNT(*) as total FROM posts WHERE user_id = ?");
$countStmt->bind_param("i", $profileId);
$countStmt->execute();
$postCount = $countStmt->get_result()->fetch_assoc()['total'];

// Galerie : récupération de toutes les publications (texte, images, vidéos et audios)
$postsStmt = $conn->prepare("SELECT id, legende, media_url, media_type FROM posts WHERE user_id = ? ORDER BY date_publication DESC");
$postsStmt->bind_param("i", $profileId);
$postsStmt->execute();
$posts = $postsStmt->get_result();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Profil - <?php echo htmlspecialchars($profileUser['username']); ?></title>
  <link rel="stylesheet" href="profil.css?v=9">
</head>
<body>

  <header class="topbar">
    <h1 onclick="window.location.href='user_page.php'" style="text-decoration:none; font-size:40px; font-weight:bold; color: red; cursor:pointer;">Mervie</h1>
    <div class="search-container">
      <input type="text" placeholder="Rechercher..." class="search-bar" id="searchInput">
      <i class="fa-solid fa-magnifying-glass search-icon"></i>
      <i class="fa-solid fa-xmark clear-icon" id="clearIcon" style="display: none;"></i>
      <div class="search-results" id="searchResults"></div>
    </div>
    <div class="user-mini">
      <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
      <img src="<?php echo htmlspecialchars($_SESSION['profile_pic'] ?: 'default.png'); ?>" alt="Avatar">
      <div class="user-dropdown">
          <a href="profil.php"><i class="fa-solid fa-user"></i> Profil</a>
          <a href="user_page.php"><i class="fa-solid fa-house"></i> Accueil</a>
          <a href="logout.php" style="color: #ff4d4d;"><i class="fa-solid fa-right-from-bracket"></i> Déconnexion</a>
      </div>
    </div>
  </header>

  <main class="profile-container">
    <section class="profile-header">
      <img src="<?php echo htmlspecialchars($profileUser['profile_pic'] ?: 'default.png'); ?>" class="profile-pic" alt="profil">
      <div class="profile-info">
        <h2><?php echo htmlspecialchars($profileUser['username']); ?></h2>
        <p class="bio"><?php echo htmlspecialchars($profileUser['bio'] ?: 'Bienvenue sur mon profil 🌍✨'); ?></p>
        <div class="stats">
          <div><strong><?php echo $postCount; ?></strong><span>Posts</span></div>
          <button class="follow-btn" id="openFollowingModal">Voir les comptes suivis</button>
        </div>
        <button class="profil-follow"><?php if ($profileUsername === $_SESSION['username']) { echo 'Modifier le profil'; } else { ?><span class="follow-action" data-profile-id="<?php echo $profileId; ?>" data-is-following="<?php echo $isFollowing ? '1' : '0'; ?>"><?php echo $isFollowing ? 'Suivi' : 'Suivre'; ?></span><?php } ?></button>
      </div>
    </section>
    <section class="gallery">
      <?php while($p = $posts->fetch_assoc()): ?>
        <div class="post" data-post-id="<?php echo $p['id']; ?>">
          <?php if($p['media_type'] === 'texte'): ?>
            <div class="post-text">
                <p><?php echo nl2br(htmlspecialchars($p['legende'])); ?></p>
            </div>
          <?php elseif($p['media_type'] === 'image'): ?>
            <img src="<?php echo htmlspecialchars($p['media_url']); ?>" alt="Publication">
          <?php elseif($p['media_type'] === 'video'): ?>
            <video src="<?php echo htmlspecialchars($p['media_url']); ?>" muted onmouseover="this.play()" onmouseout="this.pause()"></video>
          <?php elseif($p['media_type'] === 'audio'): ?>
            <div class="audio-visualizer" data-src="<?php echo htmlspecialchars($p['media_url']); ?>">
                <button type="button" class="audio-play-button">▶ Play</button>
                <canvas></canvas>
                <span class="audio-status">Audio</span>
            </div>
          <?php endif; ?>
        </div>
      <?php endwhile; ?>
    </section>
  </main>

  <!-- Modal de liste des comptes suivis -->
  <div id="followingModal" class="modal-following">
    <div class="modal-following-content">
      <span class="close-following">&times;</span>
      <h3>Comptes que je suis</h3>
      <div id="followingList" class="following-list">Chargement...</div>
    </div>
  </div>

  <!-- Modal de modification de profil -->
  <div id="editProfileModal" class="modal-edit">
    <div class="modal-edit-content">
      <span class="close-modal">&times;</span>
      <form action="profil.php<?php echo $uParam; ?>" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">
        <h3>Modifier mes informations</h3>
        <label>Numéro de téléphone :</label>
        <input type="text" name="numero" value="<?php echo htmlspecialchars($profileUser['numero'] ?? ''); ?>">
        <label>Adresse Email :</label>
        <input type="email" name="email" value="<?php echo htmlspecialchars($profileUser['email'] ?? ''); ?>" required>
        <label>Bio :</label>
        <textarea name="bio" rows="3" placeholder="Parlez-nous de vous..."><?php echo htmlspecialchars($profileUser['bio'] ?? ''); ?></textarea>
        <label>Changer la photo de profil :</label>
        <input type="file" name="profile_pic" accept="image/*">
        <button type="submit" name="update_profile" class="save-profile-btn">Enregistrer les modifications</button>
      </form>
    </div>
  </div>

    <script>
        const modal = document.getElementById("editProfileModal");
        const editBtn = document.querySelector(".profil-follow");
        const span = document.querySelector(".close-modal");

        if (editBtn && editBtn.innerText && editBtn.innerText.trim().includes("Modifier")) {
                editBtn.addEventListener('click', () => { modal.style.display = 'block'; });
        }
        if (span) span.addEventListener('click', () => { modal.style.display = 'none'; });
        window.addEventListener('click', (event) => { if (event.target == modal) modal.style.display = 'none'; });
    </script>

  <!-- Modal Lightbox pour les médias -->
  <div id="mediaModal" class="modal-media">
    <span class="close-media">&times;</span>
    <div class="modal-media-wrapper">
      <div class="modal-media-content" id="modalMediaContainer"></div>
      <div class="modal-comments-side">
        <div id="modalCommentsContainer" style="flex: 1; overflow-y: auto;"></div>
        
        <!-- Formulaire de commentaire ajouté en bas -->
        <form method="POST" action="profil.php<?php echo isset($_GET['u']) ? '?u='.urlencode($_GET['u']) : ''; ?>" enctype="multipart/form-data" class="comment-form" id="modalCommentForm">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">
          <input type="hidden" name="post_id" id="modalPostId" value="">
          <div class="wrote">
            <input class="wrotecomment" type="text" name="comment_content" placeholder="Ajouter un commentaire...">
            <button class="sendcomment" type="submit" name="submit_comment">Envoyer</button>
            <button type="button" class="sendMediaComment"><i class="fa-regular fa-image"></i></button>
            <button type="button" class="micButtonComment"><i class="fa-solid fa-microphone"></i></button>
            <input type="file" name="comment_media_file" class="comment-media-input" style="display:none;" accept="image/*,video/*,audio/*">
            <input type="hidden" class="recordedCommentAudioData" name="recorded_comment_audio_data">
          </div>
          <span class="recordStatusComment"></span>
          <div class="fileNameDisplayComment"></div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://kit.fontawesome.com/d28f9485ed.js" crossorigin="anonymous"></script>
  <script src="user_page.js?v=11"></script>
  <script>
    // Définir le token CSRF comme variable globale pour les requêtes AJAX
    window.csrfToken = '<?php echo htmlspecialchars(getCsrfToken()); ?>';
    
    // Gestion de la recherche d'utilisateurs
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    const clearIcon = document.getElementById('clearIcon');

    console.log('searchInput:', searchInput);
    console.log('searchResults:', searchResults);
    console.log('clearIcon:', clearIcon);

    if (searchInput && searchResults) {
        searchInput.addEventListener('input', function() {
            const query = this.value.trim();
            console.log('Query:', query);
            // Afficher/masquer le bouton effacer
            clearIcon.style.display = query.length > 0 ? 'flex' : 'none';
            if (query.length < 1) {
                searchResults.classList.remove('active');
                return;
            }

            fetch('search_users.php?q=' + encodeURIComponent(query))
                .then(response => {
                    console.log('Response:', response);
                    return response.json();
                })
                .then(data => {
                    console.log('Data:', data);
                    searchResults.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach(user => {
                            const item = document.createElement('div');
                            item.className = 'search-result-item';
                            item.innerHTML = `
                                <img src="${user.profile_pic}" alt="Avatar">
                                <span>${user.username}</span>
                            `;
                            item.addEventListener('click', function() {
                                window.location.href = 'profil.php?u=' + encodeURIComponent(user.username);
                            });
                            searchResults.appendChild(item);
                        });
                        searchResults.classList.add('active');
                    } else {
                        searchResults.classList.remove('active');
                    }
                })
                .catch(error => console.error('Error:', error));
        });

        // Bouton effacer
        if (clearIcon) {
            clearIcon.addEventListener('click', function() {
                searchInput.value = '';
                searchResults.classList.remove('active');
                clearIcon.style.display = 'none';
                searchInput.focus();
            });
        }

        // Fermer les résultats si clic ailleurs
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target) && !clearIcon.contains(e.target)) {
                searchResults.classList.remove('active');
            }
        });
    } else {
        console.log('Elements not found');
    }

    const followingModal = document.getElementById('followingModal');
    const openFollowingModal = document.getElementById('openFollowingModal');
    const closeFollowingModal = document.querySelector('.close-following');
    const followingList = document.getElementById('followingList');

    const refreshFollowingList = () => {
        followingList.innerHTML = 'Chargement...';
        fetch('following_list.php')
            .then(response => response.json())
                .then(data => {
                if (!data.success) {
                    followingList.innerHTML = '<p>Impossible de charger la liste.</p>';
                    return;
                }

                if (data.users.length === 0) {
                    followingList.innerHTML = '<p>Vous ne suivez encore personne.</p>';
                    return;
                }

                followingList.innerHTML = data.users.map(user => `
                    <a href="profil.php?u=${encodeURIComponent(user.username)}" class="following-item-link" style="text-decoration:none; color:inherit;">
                        <div class="following-item" data-username="${user.username}">
                            <img src="${user.profile_pic}" alt="Avatar">
                            <span>${user.username}</span>
                        </div>
                    </a>
                `).join('');
            })
            .catch(error => {
                console.error('Error loading following list:', error);
                followingList.innerHTML = '<p>Erreur lors du chargement.</p>';
            });
    };

    if (openFollowingModal && followingModal) {
        openFollowingModal.addEventListener('click', function() {
            followingModal.style.display = 'flex';
            refreshFollowingList();
        });
    }

    if (closeFollowingModal) {
        closeFollowingModal.addEventListener('click', function() {
            followingModal.style.display = 'none';
        });
    }

    window.addEventListener('click', function(e) {
        if (e.target === followingModal) {
            followingModal.style.display = 'none';
        }
    });

    // Gestion du suivi
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('follow-action')) {
            const btn = e.target;
            const profileId = btn.dataset.profileId;
            const isFollowing = btn.dataset.isFollowing === '1';
            const action = isFollowing ? 'unfollow' : 'follow';

            fetch('follow.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, profileId, csrf_token: window.csrfToken })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    btn.textContent = isFollowing ? 'Suivre' : 'Suivi';
                    btn.dataset.isFollowing = isFollowing ? '0' : '1';
                } else {
                    console.error('Follow action failed:', data.message);
                }
            })
            .catch(error => console.error('Error:', error));
        }
    });
  </script>
</body>
</html>