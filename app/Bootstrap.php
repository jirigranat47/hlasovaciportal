<?php

declare(strict_types=1);

namespace App;

use Nette\Bootstrap\Configurator;

class Bootstrap
{
	public static function boot(): Configurator
	{
		$configurator = new Configurator();

		$rootDir = dirname(__DIR__);

		// Vytvoření temp a log adresářů pokud neexistují
		if (!is_dir($rootDir . '/temp')) {
			@mkdir($rootDir . '/temp', 0777, true);
		}
		if (!is_dir($rootDir . '/log')) {
			@mkdir($rootDir . '/log', 0777, true);
		}

		$configurator->setDebugMode(true);
		$configurator->enableTracy($rootDir . '/log');

		// Suppress PHP 8.4 deprecation notices in 3rd party libraries
		\Tracy\Debugger::$strictMode = false;
		\Tracy\Debugger::$scream = false;

		$configurator->setTempDirectory($rootDir . '/temp');

		$configurator->createRobotLoader()
			->addDirectory(__DIR__)
			->register();

		$configurator->addConfig($rootDir . '/config/common.neon');
		if (file_exists($rootDir . '/config/local.neon')) {
			$configurator->addConfig($rootDir . '/config/local.neon');
		}

		return $configurator;
	}
}
