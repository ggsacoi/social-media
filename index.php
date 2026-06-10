<?php
session_start();
require_once 'config.php';

$errors = [
    'login' => $_SESSION['login_error'] ?? '',
    'register' => $_SESSION['register_error'] ?? ''
];
$activeForm = $_SESSION['active_form'] ?? 'login';

// Clear errors ONLY (keep session for CSRF token)
unset($_SESSION['login_error']);
unset($_SESSION['register_error']);
unset($_SESSION['active_form']);
    
    function showError($error) {
        return !empty($error) ? "<p class='error-message'>$error</p>" : '';
    }
    function isActiveForm($formName, $activeForm) {
        return $formName === $activeForm ? 'active' : '';
    }
    if(isset($_GET['test'])) {
    echo $_GET['test'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>connexion</title>
    <link rel="stylesheet" href="login.css">
    <!-- Google AdSense Verification Script -->
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1336558294811026" crossorigin="anonymous"></script>
</head>
<body>
        <div class="container">
            <div class="form-box <?= isActiveForm('login', $activeForm); ?>" id="login-form">
                <form action="login_register.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">
                    <h2>Login</h2>
                    <?= showError($errors['login']); ?>
                    <input type="text" name="email" placeholder="Email" required>
                    <input type="password" name="motdepasse" placeholder="Password" required>
                    <button type="submit" name="login">Login</button>
                    <p>Don't have an account? <a href="#" onclick="showForm('register-form')">Register</a></p>
                </form>
            </div>
        <div class="form-box<?= isActiveForm('register', $activeForm); ?>" id="register-form">
            <form action="login_register.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">
                <h2>Register</h2>
                <?= showError($errors['register']); ?>
                <input type="text" name="username" placeholder="username">
                <input type="tel" name="numero" placeholder="numero">
                <input type="email" name="email" placeholder="Email" required>
                <input type="password" name="motdepasse" placeholder="mot de passe" required>
                <label for="profile_pic" style="font-size: 0.8rem; margin-top: 10px; display: block;">Photo de profil (JPG uniquement) :</label>
                <input type="file" name="profile_pic" id="profile_pic" accept=".jpg, .jpeg">
                <button type="submit" name="register">Register</button>
                <p>Already have an account? <a href="#" onclick="showForm('login-form')">Login</a></p>
            </form>
        </div>
    </div>
    <script src="script.js"></script>
</body>
</html>