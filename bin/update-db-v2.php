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
	echo "Database structure version 2 applied successfully.\n";
} catch (\Throwable $e) {
	$connection->query('SET FOREIGN_KEY_CHECKS=1');
	echo "Error updating database: " . $e->getMessage() . "\n";
	exit(1);
}
