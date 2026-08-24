<?php

declare(strict_types=1);

namespace App\Presentation\Sign;

use App\Presentation\BasePresenter;
use App\Model\SkautisAuthManager;

final class SignPresenter extends BasePresenter
{
	/** @persistent */
	public ?string $backlink = null;

	public function __construct(
		private SkautisAuthManager $skautisAuthManager
	) {
		parent::__construct();
	}

	/**
	 * Přesměrování na přihlašovací stránku SkautISu
	 */
	public function actionIn(?string $backlink = null): void
	{
		$bl = $backlink ?? $this->backlink;
		if ($bl) {
			$this->getSession('auth')->backlink = $bl;
		}

		$backUrl = $this->link('//Sign:callback', $bl ? ['backlink' => $bl] : []);
		$loginUrl = $this->skautisAuthManager->getLoginUrl($backUrl);
		$this->redirectUrl($loginUrl);
	}

	/**
	 * Zpracování callbacku po přihlášení ze SkautISu
	 */
	public function actionCallback(?string $backlink = null): void
	{
		$params = array_merge($this->getHttpRequest()->getQuery(), $this->getHttpRequest()->getPost());
		
		$authSession = $this->getSession('auth');
		$bl = $backlink ?? $params['backlink'] ?? $authSession->backlink ?? $this->backlink;
		unset($authSession->backlink);

		// Pokud SkautIS vrátil parametr zabalený v ReturnUrl, vytáhneme původní backlink token
		if (!$bl && !empty($params['ReturnUrl'])) {
			$rawReturnUrl = urldecode(urldecode((string)$params['ReturnUrl']));
			$parsedUrl = parse_url($rawReturnUrl);
			if (!empty($parsedUrl['query'])) {
				parse_str($parsedUrl['query'], $queryParts);
				if (!empty($queryParts['backlink'])) {
					$bl = (string)$queryParts['backlink'];
				}
			}
		}

		if ($this->skautisAuthManager->processLoginToken($params)) {
			$this->flashMessage('Úspěšně jste se přihlásili přes SkautIS!', 'success');
			if ($bl) {
				$this->restoreRequest($bl);
			}
		} else {
			$this->flashMessage('Přihlášení přes SkautIS selhalo nebo vypršela platnost relace.', 'danger');
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
	 * Přepnutí role uživatele
	 */
	public function actionSwitchRole(int $roleId): void
	{
		if ($this->skautisAuthManager->switchRole($roleId)) {
			$userData = $this->skautisAuthManager->getUserData();
			$this->flashMessage("Aktivní role byla změněna na: {$userData['roleName']}", 'success');
		} else {
			$this->flashMessage('Změnu role se nepodařilo provést.', 'danger');
		}
		$this->redirect('Home:default');
	}

	/**
	 * Vývojový login pro simulaci uživatele (výchozí jako administrátor / vedoucí střediska)
	 */
	public function actionDevLogin(
		int $unitId = 10001,
		int $personId = 1,
		string $personName = 'Admin Testovací',
		string $roleKey = 'vedouciStredisko',
		string $roleName = 'Vedoucí střediska',
		string $unitName = 'Testovací středisko',
		?string $backlink = null
	): void {
		$this->skautisAuthManager->simulateLogin($unitId, $personId, $personName, $roleKey, $roleName, $unitName);
		$this->flashMessage("Simulované přihlášení jako administrátor: $personName (Jednotka: $unitName #$unitId, Role: $roleName)", 'success');

		$authSession = $this->getSession('auth');
		$bl = $backlink ?? $authSession->backlink ?? $this->backlink;
		unset($authSession->backlink);

		if ($bl) {
			$this->restoreRequest($bl);
		}

		$this->redirect('Home:default');
	}
}
