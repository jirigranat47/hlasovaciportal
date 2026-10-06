<?php

declare(strict_types=1);

namespace App\Presentation\Cron;

use Nette\Application\UI\Presenter;
use App\Model\CronManager;

final class CronPresenter extends Presenter
{
	public function __construct(
		private CronManager $cronManager,
		private string $cronToken,
		private \Nette\Database\Connection $connection
	) {
		parent::__construct();
	}

	public function actionRun(string $token): void
	{
		if (!hash_equals($this->cronToken, $token)) {
			$this->error('Neplatný bezpečnostní token.', 403);
		}

		try {
			$this->cronManager->run();
			$message = "Cron run completed successfully at " . date('Y-m-d H:i:s') . "\n";
		} catch (\Throwable $e) {
			$message = "Cron run failed: " . $e->getMessage() . "\n";
		}

		$this->sendResponse(new \Nette\Application\Responses\TextResponse($message));
	}

	public function actionUpdateDb(string $token): void
	{
		if (!hash_equals($this->cronToken, $token)) {
			$this->error('Neplatný bezpečnostní token.', 403);
		}


		
		$message = "Spouštím aktualizaci databáze...\n\n";

		try {
			$columns = $this->connection->query("SHOW COLUMNS FROM `users` LIKE 'custom_email'")->fetchAll();
			if (count($columns) === 0) {
				$this->connection->query("
					ALTER TABLE `users`
					ADD COLUMN `custom_email` VARCHAR(150) NULL DEFAULT NULL COMMENT 'Vlastní e-mail nastavený uživatelem' AFTER `unit_name`
				");
				$message .= "OK: Sloupec custom_email byl úspěšně přidán do tabulky users.\n";
			} else {
				$message .= "INFO: Sloupec custom_email již v tabulce users existuje.\n";
			}
		} catch (\Throwable $e) {
			$message .= "CHYBA: " . $e->getMessage() . "\n";
		}

		$message .= "\nHotovo.\n";

		$this->sendResponse(new \Nette\Application\Responses\TextResponse($message));
	}
}
