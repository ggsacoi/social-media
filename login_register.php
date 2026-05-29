<?php

session_start();
require_once 'config.php';

if(isset($_POST['register'])) {
    // Validation du token CSRF
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        die('Erreur de sécurité CSRF. Veuillez réessayer.');
    }
    
    $username = $_POST['username'];
    $numero = $_POST['numero'];
    $email = $_POST['email'];
    $motdepasse = password_hash($_POST['motdepasse'], PASSWORD_DEFAULT);

    $profile_pic = '';
    if(isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'jfif', 'webp'];
        $filename = $_FILES['profile_pic']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if(in_array($ext, $allowed)) {
            // On utilise l'username pour nommer le fichier et éviter les doublons
            $newFilename = $username . '.' . $ext;
            $uploadDir = 'uploads/avatars/';
            $targetPath = $uploadDir . $newFilename;

            if(move_uploaded_file($_FILES['profile_pic']['tmp_name'], $targetPath)) {
                $profile_pic = $targetPath;
            }
        }
    }

    $checkStmt = $conn->prepare("SELECT email FROM users WHERE email = ?");
    if (!$checkStmt) {
        die("Erreur de préparation: " . $conn->error);
    }
    $checkStmt->bind_param('s', $email);
    $checkStmt->execute();
    $checkEmail = $checkStmt->get_result();
    if($checkEmail->num_rows > 0) {
        $_SESSION['register_error'] = 'Email is already registered!';
        $_SESSION['active_form'] = 'register';
    }
    else { 
        $insertStmt = $conn->prepare("INSERT INTO users (username, numero, email, motdepasse, profile_pic) VALUES (?, ?, ?, ?, ?)");
        if (!$insertStmt) {
            die("Erreur de préparation: " . $conn->error);
        }
        $insertStmt->bind_param('sssss', $username, $numero, $email, $motdepasse, $profile_pic);
        $insertStmt->execute();
    }
    header("Location: index.php");
    exit();
}
if(isset($_POST['login'])) {
    // Validation du token CSRF
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        die('Erreur de sécurité CSRF. Veuillez réessayer.');
    }
    
    $email = $_POST['email'];
    $motdepasse = $_POST['motdepasse'];

    $loginStmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    if (!$loginStmt) {
        die("Erreur de préparation: " . $conn->error);
    }
    $loginStmt->bind_param('s', $email);
    $loginStmt->execute();
    $result = $loginStmt->get_result();
    if($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if(password_verify($motdepasse, $user['motdepasse'])) {
            htmlspecialchars($_SESSION['username'] = $user['username']);
            $_SESSION['email'] = $user['email'];            $_SESSION['profile_pic'] = $user['profile_pic'];                header("Location: user_page.php");
            exit();
        }
    }
    $_SESSION['login_error'] = 'Incorrect email or password';
    $_SESSION['active_form'] = 'login';
    header("Location: index.php");
    exit();
}
?>