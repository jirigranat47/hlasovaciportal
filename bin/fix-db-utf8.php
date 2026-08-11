<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$container = App\Bootstrap::boot()->createContainer();
$db = $container->getByType(Nette\Database\Explorer::class);

$db->query('SET NAMES utf8mb4');
$db->query('SET FOREIGN_KEY_CHECKS=0');
$db->query('TRUNCATE TABLE votes');
$db->query('TRUNCATE TABLE options');
$db->query('TRUNCATE TABLE elections');
$db->query('SET FOREIGN_KEY_CHECKS=1');

$db->table('elections')->insert([
	'id' => 1,
	'title' => 'Hlasování o názvu nové klubovny 2026',
	'description' => 'Vyberte nejvhodnější název pro naši nově zrekonstruovanou základnu.',
	'is_active' => 1,
]);

$db->table('options')->insert([
	['election_id' => 1, 'title' => 'Klubovna U Lípy'],
	['election_id' => 1, 'title' => 'Skautské centrum Junák'],
	['election_id' => 1, 'title' => 'Orlí Hnízdo'],
]);

echo "UTF-8 database seed finished successfully.\n";
