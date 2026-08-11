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
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `unit_id` INT NOT NULL COMMENT 'ID jednotky pro kterou je hlasovani urceno',
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft / published',
  `proposal_received_date` DATE NULL COMMENT 'Datum obdrzeni navrhu',
  `end_date` DATETIME NOT NULL COMMENT 'Termin dokdy se hlasuje',
  `created_by_person_id` INT NOT NULL COMMENT 'ID osoby ktera hlasovani zalozila',
  `notification_sent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Zda byl odeslan email o zahajeni',
  `results_sent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Zda byl odeslan email s vysledky',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
