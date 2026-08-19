<?php

declare(strict_types=1);

namespace App;

use Nette\Bootstrap\Configurator;

class Bootstrap
{
	public static function boot(): Configurator
	{
		date_default_timezone_set('Europe/Prague');

		$configurator = new Configurator();

		$rootDir = dirname(__DIR__);

		// Vytvoření temp a log adresářů pokud neexistují
		if (!is_dir($rootDir . '/temp')) {
			@mkdir($rootDir . '/temp', 0777, true);
		}
		if (!is_dir($rootDir . '/log')) {
			@mkdir($rootDir . '/log', 0777, true);
		}

		// Na localhostu a ve vývojovém prostředí zapneme ladicí režim Tracy, na produkci bezpečně logujeme do složky /log
		$isDev = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || (getenv('NETTE_ENV') === 'dev');
		$configurator->setDebugMode($isDev);
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
