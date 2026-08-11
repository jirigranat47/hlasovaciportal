<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

try {
	$container = App\Bootstrap::boot()->createContainer();
	/** @var App\Model\CronManager $cronManager */
	$cronManager = $container->getByType(App\Model\CronManager::class);
	$cronManager->run();
	echo "Cron manager CLI run completed successfully at " . date('Y-m-d H:i:s') . "\n";
} catch (\Throwable $e) {
	echo "Cron manager CLI run failed: " . $e->getMessage() . "\n";
	exit(1);
}
