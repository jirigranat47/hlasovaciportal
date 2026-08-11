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
		if ($token !== $this->cronToken) {
			$this->error('Neplatný bezpečnostní token.', 403);
		}

		try {
			$this->cronManager->run();
			$this->sendResponse(new \Nette\Application\Responses\TextResponse("Cron run completed successfully at " . date('Y-m-d H:i:s') . "\n"));
		} catch (\Throwable $e) {
			$this->sendResponse(new \Nette\Application\Responses\TextResponse("Cron run failed: " . $e->getMessage() . "\n"));
		}
	}
}
