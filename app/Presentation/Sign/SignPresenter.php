<?php

declare(strict_types=1);

namespace App\Presentation\Sign;

use Nette\Application\UI\Presenter;
use App\Model\SkautisAuthManager;

final class SignPresenter extends Presenter
{
	public function __construct(
		private SkautisAuthManager $skautisAuthManager
	) {
		parent::__construct();
	}

	/**
	 * Přesměrování na přihlašovací stránku SkautISu
	 */
	public function actionIn(): void
	{
		$backUrl = $this->link('//Sign:callback');
		$loginUrl = $this->skautisAuthManager->getLoginUrl($backUrl);
		$this->redirectUrl($loginUrl);
	}

	/**
	 * Zpracování callbacku po přihlášení ze SkautISu
	 */
	public function actionCallback(): void
	{
		$params = array_merge($this->getHttpRequest()->getQuery(), $this->getHttpRequest()->getPost());
		
		if ($this->skautisAuthManager->processLoginToken($params)) {
			$this->flashMessage('Úspěšně jste se přihlásili přes SkautIS!', 'success');
		} else {
			$this->flashMessage('Přihlášení přes SkautIS selhalo nebo vypršelo platné relaci.', 'danger');
		}

		$this->redirect('Home:default');
	}

	/**
	 * Odhlášení z aplikace
	 */
	public function actionOut(): void
	{
		$this->skautisAuthManager->logout();
		$this->flashMessage('Byli jste úspěšně odhlášeni.', 'info');
		$this->redirect('Home:default');
	}

	/**
	 * Vývojový login pro simulaci uživatele
	 */
	public function actionDevLogin(int $unitId, int $personId, string $personName, string $roleKey = 'clened', string $roleName = 'Člen'): void
	{
		$this->skautisAuthManager->simulateLogin($unitId, $personId, $personName, $roleKey, $roleName);
		$this->flashMessage("Simulované přihlášení jako $personName (Unit: $unitId, Person: $personId, Role: $roleKey)", 'success');
		$this->redirect('Home:default');
	}
}
