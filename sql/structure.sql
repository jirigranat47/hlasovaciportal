-- Databázová struktura pro Skautský Hlasovací Portál

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
  `unit_id` INT NULL COMMENT 'ID jednotky pro kterou je hlasovani urceno',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
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
  `voter_hash` VARCHAR(64) NOT NULL COMMENT 'Anonymizovany hash uzivatele',
  `option_id` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_vote_per_user` (`election_id`, `voter_hash`),
  FOREIGN KEY (`election_id`) REFERENCES `elections`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`option_id`) REFERENCES `options`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


INSERT INTO `elections` (`id`, `title`, `description`, `is_active`) VALUES
(1, 'Hlasování o názvu nové klubovny 2026', 'Vyberte nejvhodnější název pro naši nově zrekonstruovanou základnu.', 1);

INSERT INTO `options` (`election_id`, `title`) VALUES
(1, 'Klubovna U Lípy'),
(1, 'Skautské centrum Junák'),
(1, 'Orlí Hnízdo');
