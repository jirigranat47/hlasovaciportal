<?php

declare(strict_types=1);

namespace App\Presentation;

use Nette\Application\UI\Presenter;
use App\Model\SkautisAuthManager;
use Nette\DI\Attributes\Inject;

abstract class BasePresenter extends Presenter
{
	#[Inject]
	public SkautisAuthManager $baseAuthManager;

	protected function startup(): void
	{
		parent::startup();
		if ($this->baseAuthManager->isLoggedIn()) {
			$this->baseAuthManager->keepAlive();
			if ($this->baseAuthManager->hasSessionJustExpired()) {
				$this->flashMessage('Vaše přihlášení do SkautISu vypršelo. Přihlaste se prosím znovu.', 'warning');
				if (!$this->isLinkCurrent('Home:default') && !$this->isLinkCurrent('Sign:*')) {
					$this->redirect('Sign:in', ['backlink' => $this->storeRequest()]);
				}
			}
		}
	}

	protected function beforeRender(): void
	{
		parent::beforeRender();
		$isLoggedIn = $this->baseAuthManager->isLoggedIn();
		$this->template->isLoggedIn = $isLoggedIn;
		$this->template->userData = $isLoggedIn ? $this->baseAuthManager->getUserData() : null;
		$this->template->userRoles = $isLoggedIn ? $this->baseAuthManager->getAllUserRoles() : [];
	}

	public function handleSwitchRole(int $roleId): void
	{
		if ($this->baseAuthManager->switchRole($roleId)) {
			$userData = $this->baseAuthManager->getUserData();
			$this->flashMessage("Aktivní role byla změněna na: {$userData['roleName']}", 'success');
		} else {
			$this->flashMessage('Změnu role se nepodařilo provést.', 'danger');
		}

		$this->redirect('this');
	}
}
