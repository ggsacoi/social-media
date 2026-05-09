<?php
session_start();

$errors = [
    'login' => $_SESSION['login_error'] ?? '',
    'register' => $_SESSION['register_error'] ?? ''
    ];
    $activeForm = $_SESSION['active-form'] ?? 'login';
    
    session_unset();
    
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
</head>
<body>
        <div class="container">
            <div class="form-box <?= isActiveForm('login', $activeForm); ?>" id="login-form">
                <form action="login_register.php" method="post">
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