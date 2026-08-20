<?php

declare(strict_types=1);

namespace App\Presentation\Cron;

use Nette\Application\UI\Presenter;
use App\Model\CronManager;

final class CronPresenter extends Presenter
{
	public function __construct(
		private CronManager $cronManager,
		private string $cronToken
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
}
