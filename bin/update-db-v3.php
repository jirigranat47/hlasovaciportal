<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$container = App\Bootstrap::boot()->createContainer();
/** @var Nette\Database\Connection $connection */
$connection = $container->getByType(Nette\Database\Connection::class);

try {
	$connection->query("
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
	");

	$connection->query("
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
	");

	echo "Audit log tables created successfully.\n";
} catch (\Throwable $e) {
	echo "Error creating audit tables: " . $e->getMessage() . "\n";
	exit(1);
}
