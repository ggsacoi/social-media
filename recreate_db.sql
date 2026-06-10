-- Script pour recréer la base de données systeme-relationnel-humain avec support multimédia

CREATE DATABASE IF NOT EXISTS `systeme-relationnel-humain`;
USE `systeme-relationnel-humain`;

-- 1. Table users
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(255) UNIQUE NOT NULL,
    `numero` VARCHAR(20),
    `email` VARCHAR(255) UNIQUE NOT NULL,
    `profile_pic` VARCHAR(255) NULL DEFAULT 'default.png',
    `bio` TEXT NULL,
    `nb_following` INT DEFAULT 0,
    `motdepasse` VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `follows` (
    `follower_id` INT(10) UNSIGNED NOT NULL,
    `followed_id` INT(10) UNSIGNED NOT NULL,
    PRIMARY KEY (`follower_id`, `followed_id`),
    FOREIGN KEY (`follower_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`followed_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2. Table messages
CREATE TABLE IF NOT EXISTS `messages` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `id_expediteur` INT(10) UNSIGNED NOT NULL,
    `id_destinataire` INT(10) UNSIGNED NOT NULL,
    `contenu` TEXT NOT NULL,
    `media_url` VARCHAR(255) NULL, -- Ajouté ici
    `media_type` ENUM('texte', 'image', 'video', 'audio') DEFAULT 'texte', -- Ajouté ici
    `date_envoi` DATETIME NOT NULL,
    `lu` TINYINT(1) DEFAULT 0,
    FOREIGN KEY (`id_expediteur`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`id_destinataire`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 1. Table des Posts (mise à jour)
CREATE TABLE IF NOT EXISTS `posts` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `legende` TEXT,
    `media_url` VARCHAR(255),
    `media_type` ENUM('texte', 'image', 'video', 'audio') DEFAULT 'texte',
    `date_publication` DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_post FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. Table comments
CREATE TABLE IF NOT EXISTS `comments` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `post_id` INT(10) UNSIGNED NOT NULL,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `contenu` TEXT NOT NULL,
    `media_url` VARCHAR(255) NULL,
    `media_type` ENUM('texte', 'image', 'video', 'audio') DEFAULT 'texte',
    `date_comment` DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_comment_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_comment_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. Table livestreams
CREATE TABLE IF NOT EXISTS `livestreams` (
    `id` INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `title` VARCHAR(255),
    `start_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `end_time` DATETIME NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    CONSTRAINT `fk_livestream_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
