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

			$this->skautis->setToken($token);
			if ($roleId !== null) {
				$this->skautis->setRoleId($roleId);
			}
			if ($unitId !== null) {
				$this->skautis->setUnitId($unitId);
			}

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
			$this->skautis->setToken($this->session->token);
			if (!empty($this->session->roleId)) {
				$this->skautis->setRoleId($this->session->roleId);
			}
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
		];
	}

	/**
	 * Generuje unikátní anonymní hash pro hlasování (SHA256 z ID osoby + tajného saltu)
	 */
	public function getVoterHash(int $electionId): string
	{
		$personId = $this->session->personId ?? 0;
		$salt = 'skautis_voting_portal_secret_salt_2026';
		return hash('sha256', $electionId . '_' . $personId . '_' . $salt);
	}

	/**
	 * Vrátí URL pro odhlášení ze SkautISu
	 */
	public function getLogoutUrl(string $backUrl): string
	{
		return $this->skautis->getLogoutUrl($backUrl);
	}

	/**
	 * Zruší lokální relaci
	 */
	public function logout(): void
	{
		$this->session->remove();
	}
}
