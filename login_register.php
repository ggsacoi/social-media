<?php

session_start();
require_once 'config.php';

if(isset($_POST['register'])) {
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

    $checkEmail = $conn->query("SELECT email FROM users WHERE email = '$email'");
    if($checkEmail->num_rows > 0) {
        $_SESSION['register_error'] = 'Email is already registered!';
        $_SESSION['active_form'] = 'register';
    }
    else { 
        $conn->query("INSERT INTO users (username, numero, email, motdepasse, profile_pic) VALUES ('$username','$numero', '$email', '$motdepasse', '$profile_pic')"); 
    }
    header("Location: index.php");
    exit();
}
if(isset($_POST['login'])) {
    $email = $_POST['email'];
    $motdepasse = $_POST['motdepasse'];

    $result = $conn->query("SELECT * FROM users WHERE email = '$email'");
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