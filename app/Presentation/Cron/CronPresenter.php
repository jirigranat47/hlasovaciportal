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

		$startTime = microtime(true);
		$startDate = new \DateTime();
		$ipAddress = $this->getHttpRequest()->getRemoteAddress();

		$logId = $this->cronManager->logCronStart($startDate, $ipAddress);

		try {
			$stats = $this->cronManager->run();
			$duration = round(microtime(true) - $startTime, 2);
			$totalEmails = ($stats['new'] ?? 0) + ($stats['reminders'] ?? 0) + ($stats['results'] ?? 0);
			$message = "Cron run completed successfully at " . date('Y-m-d H:i:s') . " ({$duration}s). " .
				"New digests: {$stats['new']}, Reminders: {$stats['reminders']}, Results: {$stats['results']}.";
			$this->cronManager->logCronFinish($logId, 'success', $message, $totalEmails, $duration);
		} catch (\Throwable $e) {
			$duration = round(microtime(true) - $startTime, 2);
			$message = "Cron run failed at " . date('Y-m-d H:i:s') . " ({$duration}s): " . $e->getMessage();
			$this->cronManager->logCronFinish($logId, 'error', $message, 0, $duration);
		}

		$this->sendResponse(new \Nette\Application\Responses\TextResponse($message . "\n"));
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

			// Vytvoření cron_logs a email_logs pro monitoring
			$this->connection->query("
				CREATE TABLE IF NOT EXISTS `cron_logs` (
				  `id` INT AUTO_INCREMENT PRIMARY KEY,
				  `started_at` DATETIME NOT NULL,
				  `finished_at` DATETIME NULL,
				  `duration_seconds` FLOAT NULL,
				  `status` VARCHAR(20) NOT NULL DEFAULT 'running' COMMENT 'running / success / error',
				  `message` TEXT NULL,
				  `emails_sent` INT NOT NULL DEFAULT 0,
				  `ip_address` VARCHAR(45) NULL,
				  INDEX (`started_at`),
				  INDEX (`status`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
			");
			$message .= "OK: Tabulka cron_logs zkontrolována/vytvořena.\n";

			$this->connection->query("
				CREATE TABLE IF NOT EXISTS `email_logs` (
				  `id` INT AUTO_INCREMENT PRIMARY KEY,
				  `unit_id` INT NULL COMMENT 'ID jednotky',
				  `recipient_email` VARCHAR(150) NOT NULL,
				  `recipient_name` VARCHAR(150) NULL,
				  `subject` VARCHAR(255) NOT NULL,
				  `type` VARCHAR(50) NOT NULL DEFAULT 'notification' COMMENT 'new_elections / reminder / results / test / notification',
				  `status` VARCHAR(20) NOT NULL DEFAULT 'sent' COMMENT 'sent / failed',
				  `error_message` TEXT NULL,
				  `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				  INDEX (`sent_at`),
				  INDEX (`unit_id`, `sent_at`),
				  INDEX (`status`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
			");
			$message .= "OK: Tabulka email_logs zkontrolována/vytvořena.\n";

		} catch (\Throwable $e) {
			$message .= "CHYBA: " . $e->getMessage() . "\n";
		}

		$message .= "\nHotovo.\n";

		$this->sendResponse(new \Nette\Application\Responses\TextResponse($message));
	}
}
