<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();
date_default_timezone_set('Europe/Prague');

$tempDir = __DIR__ . '/../temp/tests';
@mkdir($tempDir, 0777, true);

$configurator = new Nette\Bootstrap\Configurator;
$configurator->setDebugMode(false);
$configurator->setTempDirectory($tempDir);

$configurator->createRobotLoader()
	->addDirectory(__DIR__ . '/../app')
	->addDirectory(__DIR__)
	->register();

$configurator->addConfig(__DIR__ . '/../config/common.neon');

if (is_file(__DIR__ . '/config/local.neon')) {
	$configurator->addConfig(__DIR__ . '/config/local.neon');
}

return $configurator->createContainer();
