-- Databázová struktura pro Skautský Hlasovací Portál v2

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `skautis_person_id` INT NOT NULL UNIQUE,
  `full_name` VARCHAR(150) NOT NULL,
  `unit_name` VARCHAR(150) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `elections` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `resolution_number` VARCHAR(100) NOT NULL COMMENT 'Cislo usneseni',
  `title` TEXT NOT NULL COMMENT 'Text usneseni',
  `description` TEXT NULL COMMENT 'Poznamka s podklady',
  `unit_id` INT NOT NULL COMMENT 'ID jednotky pro kterou je hlasovani urceno',
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft / published / cancelled',
  `proposal_received_date` DATE NULL COMMENT 'Datum obdrzeni navrhu',
  `end_date` DATETIME NOT NULL COMMENT 'Termin dokdy se hlasuje',
  `created_by_person_id` INT NOT NULL COMMENT 'ID osoby ktera hlasovani zalozila',
  `cancellation_reason` TEXT NULL COMMENT 'Duvod stornovani hlasovani',
  `cancelled_at` DATETIME NULL COMMENT 'Datum a cas stornovani',
  `cancelled_by_person_id` INT NULL COMMENT 'ID osoby ktera hlasovani stornovala',
  `notification_sent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Zda byl odeslan email o zahajeni',
  `reminder_sent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Zda byla odeslana denni upominka',
  `results_sent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Zda byl odeslan email s vysledky',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_resolution_per_unit` (`unit_id`, `resolution_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `options` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `election_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `votes_count` INT NOT NULL DEFAULT 0,
  FOREIGN KEY (`election_id`) REFERENCES `elections`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `votes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `election_id` INT NOT NULL,
  `person_id` INT NOT NULL COMMENT 'SkautIS ID osoby hlasujiciho',
  `person_name` VARCHAR(150) NOT NULL COMMENT 'Jmeno hlasujiciho',
  `option_id` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_vote_per_user` (`election_id`, `person_id`),
  FOREIGN KEY (`election_id`) REFERENCES `elections`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`option_id`) REFERENCES `options`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `council_members` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `unit_id` INT NOT NULL COMMENT 'ID jednotky',
  `person_id` INT NOT NULL COMMENT 'SkautIS ID osoby clena rady',
  `full_name` VARCHAR(150) NOT NULL COMMENT 'Jmeno clena rady',
  `email` VARCHAR(150) NULL COMMENT 'E-mail clena rady ze skautISu',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_member_per_unit` (`unit_id`, `person_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `unit_settings` (
  `unit_id` INT PRIMARY KEY COMMENT 'ID jednotky',
  `allow_custom_end_time` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = povoleno zadavat cas konce hlasovani',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `smtp_settings` (
  `unit_id` INT PRIMARY KEY COMMENT 'ID jednotky',
  `host` VARCHAR(100) NOT NULL,
  `port` INT NOT NULL,
  `username` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `secure` VARCHAR(10) NOT NULL DEFAULT 'tls' COMMENT 'tls / ssl / empty',
  `from_email` VARCHAR(100) NOT NULL,
  `from_name` VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vote_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `election_id` INT NOT NULL,
  `person_id` INT NOT NULL COMMENT 'SkautIS ID hlasujiciho',
  `person_name` VARCHAR(150) NOT NULL,
  `option_id` INT NOT NULL,
  `option_title` VARCHAR(100) NOT NULL,
  `action` VARCHAR(20) NOT NULL COMMENT 'voted / changed',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`election_id`) REFERENCES `elections`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`option_id`) REFERENCES `options`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `election_audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `election_id` INT NOT NULL,
  `person_id` INT NOT NULL COMMENT 'SkautIS ID osoby ktera zmenu provedla',
  `person_name` VARCHAR(150) NOT NULL COMMENT 'Jmeno osoby',
  `role_name` VARCHAR(150) NULL COMMENT 'Role v dobe provedeni akce',
  `action` VARCHAR(50) NOT NULL COMMENT 'created_draft, updated_draft, published, cancelled, closed, vote_cast, vote_changed',
  `details` TEXT NULL COMMENT 'Podrobnosti zmeny / duvod / text',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`election_id`, `created_at`),
  FOREIGN KEY (`election_id`) REFERENCES `elections`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_login_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `person_id` INT NOT NULL COMMENT 'SkautIS Person ID',
  `user_name` VARCHAR(150) NOT NULL COMMENT 'SkautIS uzivatelske jmeno',
  `person_name` VARCHAR(150) NOT NULL COMMENT 'Cele jmeno',
  `unit_id` INT NULL COMMENT 'ID jednotky',
  `unit_name` VARCHAR(150) NULL COMMENT 'Nazev jednotky',
  `role_name` VARCHAR(150) NULL COMMENT 'Role uzivatele',
  `action` VARCHAR(50) NOT NULL DEFAULT 'login' COMMENT 'login / role_switch / logout',
  `ip_address` VARCHAR(45) NULL COMMENT 'IP adresa uzivatele',
  `user_agent` VARCHAR(255) NULL COMMENT 'User Agent prohlizece',
  `logged_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`person_id`, `logged_at`),
  INDEX (`unit_id`, `logged_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

