<?php

declare(strict_types=1);

namespace App\Model;

use Skautis\Skautis;
use Nette\Http\Session;
use Nette\Http\SessionSection;
use Nette\Http\Request;

class SkautisAuthManager
{
	private SessionSection $session;

	public function __construct(
		private Skautis $skautis,
		Session $session,
		private VotingRepository $votingRepository,
		private Request $httpRequest,
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
			$userDetail = null;
			try {
				$userDetail = $this->skautis->user->UserDetail();
				$this->session->personId = $userDetail->ID_Person ?? null;
				$this->session->userName = $userDetail->UserName ?? 'Skaut';
				
				$personName = '';
				if (!empty($userDetail->Person)) {
					$personName = $userDetail->Person;
				} elseif (!empty($userDetail->PersonGivenName) || !empty($userDetail->PersonFamilyName)) {
					$personName = trim(($userDetail->PersonGivenName ?? '') . ' ' . ($userDetail->PersonFamilyName ?? ''));
				} else {
					$personName = $userDetail->UserName ?? 'Skaut';
				}
				$this->session->personName = $personName;
			} catch (\Throwable $e) {
				\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
				$this->session->userName = 'Skaut';
				$this->session->personName = 'Skaut';
			}

			// Získáme detaily o všech rolích a aktivní roli pro kontrolu administrátorských práv
			$this->session->roleName = '';
			$this->session->roleKey = '';
			$this->session->allRoles = [];

			if (!empty($userDetail?->ID)) {
				try {
					$roles = $this->skautis->user->UserRoleAll([
						'ID_User' => $userDetail->ID,
					]);
					
					// Převedeme na čisté asociativní pole se všemi parametry
					$rolesArray = json_decode(json_encode($roles), true);
					if (isset($rolesArray['ID'])) {
						// Pokud byla vrácena pouze jedna role (jako asociativní pole místo pole rolí)
						$rolesArray = [$rolesArray];
					}
					$this->session->allRoles = $rolesArray;

					// Tracy debug výpis
					\Tracy\Debugger::barDump($rolesArray, 'SkautIS - Všechny role uživatele (UserRoleAll)');
					\Tracy\Debugger::log($rolesArray, 'user-roles');

					if (is_iterable($roles)) {
						foreach ($roles as $role) {
							if ($roleId !== null && ((int)($role->ID ?? 0) === $roleId || (int)($role->ID_Role ?? 0) === $roleId)) {
								$this->session->roleName = $role->Role ?? ($role->DisplayName ?? '');
								$this->session->roleKey = $role->Key ?? '';
								$this->session->unitName = $role->Unit ?? '';
								if (!empty($role->ID_Unit)) {
									$this->session->unitId = (int)$role->ID_Unit;
								}
								break;
							}
						}
					}
					if (empty($this->session->unitName) && !empty($rolesArray[0]['Unit'])) {
						$this->session->unitName = $rolesArray[0]['Unit'];
					}
				} catch (\Throwable $e) {
					\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
				}
			}

			// Auditní logování přihlášení
			try {
				$ip = $this->httpRequest->getRemoteAddress();
				$userAgent = $this->httpRequest->getHeader('User-Agent');
				$this->votingRepository->logUserLogin(
					(int)($this->session->personId ?? 0),
					$this->session->userName ?? 'Skaut',
					$this->session->personName ?? 'Neznámý',
					$this->session->unitId ? (int)$this->session->unitId : null,
					$this->session->unitName ?? null,
					$this->session->roleName ?? null,
					$ip,
					$userAgent,
					'login'
				);
			} catch (\Throwable $e) {
				\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
			}

			return true;
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
			'unitName' => $this->session->unitName ?? ($this->session->unitId ? (string)$this->session->unitId : ''),
			'roleName' => $this->session->roleName ?? '',
			'roleKey' => $this->session->roleKey ?? '',
		];
	}

	/**
	 * Vrátí všechny role uživatele ze SkautISu
	 */
	public function getAllUserRoles(): array
	{
		return $this->session->allRoles ?? [];
	}

	private ?string $lastError = null;

	public function getLastError(): ?string
	{
		return $this->lastError;
	}

	/**
	 * Přepne aktivní roli přihlášeného uživatele
	 */
	public function switchRole(int $userRoleId): bool
	{
		if (!$this->isLoggedIn()) {
			return false;
		}

		$roles = $this->getAllUserRoles();
		foreach ($roles as $role) {
			if ((int)($role['ID'] ?? 0) === $userRoleId || (int)($role['ID_Role'] ?? 0) === $userRoleId) {
				$roleId = (int)$role['ID'];
				$unitId = isset($role['ID_Unit']) ? (int)$role['ID_Unit'] : null;

				$this->session->roleId = $roleId;
				$this->session->unitId = $unitId;
				$this->session->roleName = $role['Role'] ?? ($role['DisplayName'] ?? '');
				$this->session->roleKey = $role['Key'] ?? '';
				$this->session->unitName = $role['Unit'] ?? '';

				// 1. Přepneme roli přímo na serveru SkautISu
				try {
					$this->skautis->user->LoginUpdate([
						'ID' => $this->session->token,
						'ID_UserRole' => $roleId,
					]);
				} catch (\Throwable $e) {
					\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
				}

				// 2. Aktualizujeme data v instanci Skautis knihovny
				$this->skautis->getUser()->updateLoginData(
					$this->session->token,
					$roleId,
					$unitId
				);

				// 3. Auditní logování přepnutí role
				try {
					$ip = $this->httpRequest->getRemoteAddress();
					$userAgent = $this->httpRequest->getHeader('User-Agent');
					$this->votingRepository->logUserLogin(
						(int)($this->session->personId ?? 0),
						$this->session->userName ?? 'Skaut',
						$this->session->personName ?? 'Neznámý',
						$unitId,
						$this->session->unitName,
						$this->session->roleName,
						$ip,
						$userAgent,
						'role_switch'
					);
				} catch (\Throwable $e) {
					\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
				}

				return true;
			}
		}

		return false;
	}

	/**
	 * Ověří, zda je aktuálně přihlášený uživatel administrátorem (činovníkem) jednotky
	 */
	public function isAdmin(): bool
	{
		if (!$this->isLoggedIn()) {
			return false;
		}

		$adminKeys = ['vedouciKraj', 'vedouciOkres', 'vedouciStredisko'];
		$roleKey = $this->session->roleKey ?? '';

		foreach ($adminKeys as $key) {
			if (stripos($roleKey, $key) !== false) {
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
		$this->lastError = null;
		if (!$this->isLoggedIn() || empty($this->session->unitId)) {
			return [];
		}

		// Obnovíme / prodloužíme přihlašovací relaci
		try {
			$this->skautis->user->LoginUpdateRefresh(['ID' => $this->session->token]);
		} catch (\Throwable $e) {
			\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
		}

		$members = [];
		$errors = [];

		// 1. Zkusíme UserRoleALLUnit z UserManagement (všechny osoby s rolí v jednotce)
		try {
			$roleList = $this->skautis->user->UserRoleALLUnit([
				'ID_Unit' => (int)$this->session->unitId,
			]);

			if (is_iterable($roleList)) {
				foreach ($roleList as $ur) {
					$pId = (int)($ur->ID_Person ?? 0);
					if ($pId > 0) {
						$members[$pId] = [
							'personId' => $pId,
							'fullName' => $ur->Person ?? ($ur->DisplayName ?? "Osoba #$pId"),
							'role' => $ur->Role ?? '',
							'email' => null,
						];
					}
				}
			}
		} catch (\Throwable $e) {
			$errors[] = 'UserRoleALLUnit: ' . $e->getMessage();
			\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
		}

		// 2. Zkusíme PersonAll z OrganizationUnit (všechny osoby v jednotce)
		try {
			$list = $this->skautis->org->PersonAll([
				//'ID_Unit' => (int)$this->session->unitId,
				'ID_Unit' => 25784
			]);

			if (is_iterable($list)) {
				foreach ($list as $p) {
					$fullName = !empty($p->DisplayName) ? $p->DisplayName : trim(($p->FirstName ?? '') . ' ' . ($p->LastName ?? ''));
					if (!empty($p->NickName) && !str_contains($fullName, $p->NickName)) {
						$fullName .= " ({$p->NickName})";
					}
					$pId = (int)$p->ID;
					if ($pId > 0) {
						$members[$pId] = [
							'personId' => $pId,
							'fullName' => $fullName,
							'email' => $p->Email ?? ($p->EmailDefault ?? ($members[$pId]['email'] ?? null)),
						];
					}
				}
			}
		} catch (\Throwable $e) {
			$errors[] = 'PersonAll: ' . $e->getMessage();
			\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
		}

		// 3. Zkusíme MembershipAll z OrganizationUnit
		if (count($members) < 3) {
			try {
				$mList = $this->skautis->org->MembershipAll([
					'ID_Unit' => (int)$this->session->unitId,
					'IsValid' => true,
				]);
				if (is_iterable($mList)) {
					foreach ($mList as $m) {
						$pId = (int)($m->ID_Person ?? $m->ID ?? 0);
						if ($pId > 0 && !isset($members[$pId])) {
							$members[$pId] = [
								'personId' => $pId,
								'fullName' => $m->Person ?? ($m->DisplayName ?? "Osoba #$pId"),
								'email' => null,
							];
						}
					}
				}
			} catch (\Throwable $e2) {
				$errors[] = 'MembershipAll: ' . $e2->getMessage();
				\Tracy\Debugger::log($e2, \Tracy\ILogger::WARNING);
			}
		}

		if (empty($members) && !empty($errors)) {
			$this->lastError = implode(' | ', $errors);
		}

		$result = array_values($members);
		// Setřídíme abecedně podle jména
		usort($result, fn($a, $b) => strcmp($a['fullName'], $b['fullName']));

		return $result;
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
