<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$container = App\Bootstrap::boot()->createContainer();
/** @var Nette\Database\Connection $connection */
$connection = $container->getByType(Nette\Database\Connection::class);

$sql = file_get_contents(__DIR__ . '/../sql/structure.sql');

// Rozdělíme SQL soubor na jednotlivé dotazy
$queries = array_filter(
	array_map('trim', explode(';', $sql)),
	fn($query) => $query !== ''
);

$connection->query('SET FOREIGN_KEY_CHECKS=0');
try {
	// Smažeme tabulky v opačném pořadí závislostí
	$connection->query('DROP TABLE IF EXISTS `vote_history`');
	$connection->query('DROP TABLE IF EXISTS `votes`');
	$connection->query('DROP TABLE IF EXISTS `options`');
	$connection->query('DROP TABLE IF EXISTS `elections`');
	$connection->query('DROP TABLE IF EXISTS `users`');
	$connection->query('DROP TABLE IF EXISTS `council_members`');
	$connection->query('DROP TABLE IF EXISTS `smtp_settings`');
	$connection->query('SET FOREIGN_KEY_CHECKS=1');

	foreach ($queries as $query) {
		$connection->query($query);
	}

	// Vložíme testovací členy rady pro jednotku 123
	$connection->query("
		INSERT INTO `council_members` (`unit_id`, `person_id`, `full_name`, `email`) VALUES
		(123, 1001, 'Jan Novak', 'jan.novak@skaut.cz'),
		(123, 1002, 'Petr Svoboda', 'petr.svoboda@skaut.cz'),
		(123, 1003, 'Marie Dvořáková', 'marie.dvorakova@skaut.cz'),
		(123, 1004, 'Tomáš Kučera', 'tomas.kucera@skaut.cz'),
		(123, 1005, 'Lucie Černá', 'lucie.cerna@skaut.cz')
		ON DUPLICATE KEY UPDATE `full_name` = VALUES(`full_name`), `email` = VALUES(`email`);
	");

	echo "Database structure version 2 applied and test council members added successfully.\n";
} catch (\Throwable $e) {
	$connection->query('SET FOREIGN_KEY_CHECKS=1');
	echo "Error updating database: " . $e->getMessage() . "\n";
	exit(1);
}
