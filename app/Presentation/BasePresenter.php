<?php

declare(strict_types=1);

namespace App\Presentation;

use Nette\Application\UI\Presenter;
use App\Model\SkautisAuthManager;

abstract class BasePresenter extends Presenter
{
	/** @inject */
	public SkautisAuthManager $baseAuthManager;

	protected function beforeRender(): void
	{
		parent::beforeRender();
		$isLoggedIn = $this->baseAuthManager->isLoggedIn();
		$this->template->isLoggedIn = $isLoggedIn;
		$this->template->userData = $isLoggedIn ? $this->baseAuthManager->getUserData() : null;
	}
}
