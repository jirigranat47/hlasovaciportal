<?php

declare(strict_types=1);

namespace App\Model;

use Skautis\Skautis;
use Nette\Http\Session;
use Nette\Http\SessionSection;

class SkautisAuthManager
{
	private SessionSection $session;

	public function __construct(
		private Skautis $skautis,
		Session $session,
		private string $appId,
		private bool $isTest = true
	) {
		$this->session = $session->getSection('skautis_auth');
	}

	/**
	 * Vrátí URL pro přesměrování na přihlašovací stránku SkautISu
	 */
	public function getLoginUrl(string $backUrl): string
	{
		return $this->skautis->getLoginUrl($backUrl);
	}

	/**
	 * Zpracuje token z odkazovaného callbacku
	 */
	public function processLoginToken(array $params): bool
	{
		if (isset($params['skautIS_Token'])) {
			$token = $params['skautIS_Token'];
			$roleId = isset($params['skautIS_IDRole']) ? (int)$params['skautIS_IDRole'] : null;
			$unitId = isset($params['skautIS_IDUnit']) ? (int)$params['skautIS_IDUnit'] : null;

			$this->skautis->getUser()->updateLoginData($token, $roleId, $unitId);

			// Uložíme do Nette Session
			$this->session->token = $token;
			$this->session->roleId = $roleId;
			$this->session->unitId = $unitId;

			// Načteme a uložíme základní údaje o uživateli ze SkautISu
			try {
				$userDetail = $this->skautis->userManagement->UserDetail();
				$this->session->personId = $userDetail->ID_Person ?? null;
				$this->session->userName = $userDetail->UserName ?? 'Skaut';
				$this->session->personName = ($userDetail->PersonGivenName ?? '') . ' ' . ($userDetail->PersonFamilyName ?? '');

				// Získáme detaily o aktivní roli pro kontrolu administrátorských práv
				$this->session->roleName = '';
				$this->session->roleKey = '';
				if ($roleId !== null) {
					$roles = $this->skautis->userManagement->UserRoleAll();
					foreach ($roles as $role) {
						if ((int)$role->ID === $roleId) {
							$this->session->roleName = $role->RoleName ?? '';
							$this->session->roleKey = $role->RoleKey ?? '';
							break;
						}
					}
				}

				return true;
			} catch (\Throwable $e) {
				// Pokud selže načtení UserDetail (např. vypršel token)
				$this->logout();
				return false;
			}
		}

		return false;
	}

	/**
	 * Zkontroluje, zda je uživatel přihlášen
	 */
	public function isLoggedIn(): bool
	{
		if (!empty($this->session->token)) {
			$this->skautis->getUser()->updateLoginData(
				$this->session->token,
				$this->session->roleId ?? null,
				$this->session->unitId ?? null
			);
			return true;
		}
		return false;
	}

	/**
	 * Vrátí údaje o přihlášeném uživateli
	 */
	public function getUserData(): array
	{
		return [
			'personId' => $this->session->personId ?? 0,
			'userName' => $this->session->userName ?? 'Host',
			'personName' => $this->session->personName ?? 'Neznámý skaut',
			'roleId' => $this->session->roleId ?? 0,
			'unitId' => $this->session->unitId ?? 0,
			'roleName' => $this->session->roleName ?? '',
			'roleKey' => $this->session->roleKey ?? '',
		];
	}

	/**
	 * Ověří, zda je aktuálně přihlášený uživatel administrátorem (činovníkem) jednotky
	 */
	public function isAdmin(): bool
	{
		if (!$this->isLoggedIn()) {
			return false;
		}

		$adminKeys = ['administrator', 'vedouci', 'hospodar', 'tajemnik', 'mistopredseda'];
		$roleKey = strtolower($this->session->roleKey ?? '');

		foreach ($adminKeys as $key) {
			if (str_contains($roleKey, $key)) {
				return true;
			}
		}

		$roleName = mb_strtolower($this->session->roleName ?? '', 'utf-8');
		$adminWords = ['administrátor', 'vedoucí', 'hospodář', 'tajemník', 'místopředseda', 'předseda'];
		foreach ($adminWords as $word) {
			if (str_contains($roleName, $word)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Získá seznam osob v aktivní jednotce ze skautISu pro naplnění Rady jednotky
	 */
	public function getUnitMembers(): array
	{
		if (!$this->isLoggedIn() || empty($this->session->unitId)) {
			return [];
		}

		$members = [];
		try {
			$list = $this->skautis->organizationManagement->PersonAll([
				'ID_Unit' => $this->session->unitId,
			]);

			foreach ($list as $p) {
				$members[] = [
					'personId' => (int)$p->ID,
					'fullName' => ($p->FirstName ?? '') . ' ' . ($p->LastName ?? ''),
					'email' => $p->Email ?? ($p->EmailDefault ?? null),
				];
			}
		} catch (\Throwable $e) {
			// Vrátíme prázdné pole, pokud se nepodaří načíst (např. chybí práva na PersonAll ve Skautisu)
		}

		// Setřídíme abecedně podle jména
		usort($members, fn($a, $b) => strcmp($a['fullName'], $b['fullName']));

		return $members;
	}

	/**
	 * Vrátí URL pro odhlášení ze SkautISu
	 */
	public function getLogoutUrl(string $backUrl): string
	{
		return $this->skautis->getLogoutUrl($backUrl);
	}

	/**
	 * Simuluje přihlášení pro vývoj a testování
	 */
	public function simulateLogin(int $unitId, int $personId, string $personName, string $roleKey, string $roleName): void
	{
		$this->session->token = 'mock_token';
		$this->session->roleId = 12345;
		$this->session->unitId = $unitId;
		$this->session->personId = $personId;
		$this->session->userName = 'mock_user';
		$this->session->personName = $personName;
		$this->session->roleKey = $roleKey;
		$this->session->roleName = $roleName;
	}

	/**
	 * Zruší lokální relaci
	 */
	public function logout(): void
	{
		$this->session->remove();
	}
}
