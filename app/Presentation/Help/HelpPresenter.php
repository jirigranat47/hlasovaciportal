<?php

declare(strict_types=1);

namespace App\Presentation\Help;

use App\Presentation\BasePresenter;
use App\Model\SkautisAuthManager;

final class HelpPresenter extends BasePresenter
{
	public function __construct(
		private SkautisAuthManager $skautisAuthManager
	) {
		parent::__construct();
	}

	public function renderDefault(): void
	{
		$isLoggedIn = $this->skautisAuthManager->isLoggedIn();
		$this->template->isLoggedIn = $isLoggedIn;
		$this->template->isAdmin = $isLoggedIn ? $this->skautisAuthManager->isAdmin() : false;
	}
}
