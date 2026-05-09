-- Script pour recréer la base de données login-portfolio avec support multimédia

CREATE DATABASE IF NOT EXISTS `login-portfolio`;
USE `login-portfolio`;

-- 1. Table users
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(255) UNIQUE NOT NULL,
    `numero` VARCHAR(20),
    `email` VARCHAR(255) UNIQUE NOT NULL,
    `profile_pic` VARCHAR(255) NULL DEFAULT 'default.png',
    `motdepasse` VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- 2. Table messages
CREATE TABLE IF NOT EXISTS `messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_expediteur` INT NOT NULL,
    `id_destinataire` INT NOT NULL,
    `contenu` TEXT NOT NULL,
    `media_url` VARCHAR(255) NULL, -- Ajouté ici
    `media_type` ENUM('texte', 'image', 'video', 'audio') DEFAULT 'texte', -- Ajouté ici
    `date_envoi` DATETIME NOT NULL,
    `lu` TINYINT(1) DEFAULT 0,
    FOREIGN KEY (`id_expediteur`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`id_destinataire`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;