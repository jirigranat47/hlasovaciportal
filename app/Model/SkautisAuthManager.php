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
		private Session $sessionManager,
		private VotingRepository $votingRepository,
		private Request $httpRequest,
		private string $appId,
		private bool $isTest = true,
		private bool $debugRoles = false
	) {
		$this->session = $sessionManager->getSection('skautis_auth');
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

					// Tracy debug výpis (pokud je zapnut v neon konfiguraci: skautis.debugRoles)
					if ($this->debugRoles) {
						\Tracy\Debugger::barDump($rolesArray, 'SkautIS - Všechny role uživatele (UserRoleAll)');
						\Tracy\Debugger::log($rolesArray, 'user-roles');
					}

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

			// Ochrana proti Session Fixation – vygenerujeme nové ID relace po přihlášení
			$this->sessionManager->regenerateId();

			return true;
		}

		return false;
	}

	private bool $sessionJustExpired = false;

	public function hasSessionJustExpired(): bool
	{
		return $this->sessionJustExpired;
	}

	/**
	 * Aktivně udržuje a obnovuje přihlášení do SkautISu (Keep-Alive)
	 * Prodlouží token každé 2 minuty při aktivitě uživatele
	 */
	public function keepAlive(bool $force = false): bool
	{
		if (empty($this->session->token)) {
			return false;
		}

		if ($this->session->token === 'mock_token') {
			return true;
		}

		$now = time();
		$lastRefresh = (int)($this->session->lastRefresh ?? 0);

		// Obnovujeme max 1x za 2 minuty (120 s), aby se neposílaly zbytečné SOAP dotazy na každý klik
		if (!$force && ($now - $lastRefresh) < 120) {
			return true;
		}

		try {
			$this->skautis->user->LoginUpdateRefresh(['ID' => $this->session->token]);
			$this->session->lastRefresh = $now;
			return true;
		} catch (\Throwable $e) {
			if ($this->isAuthenticationError($e)) {
				\Tracy\Debugger::log('SkautIS session expired during keepAlive: ' . $e->getMessage(), \Tracy\ILogger::INFO);
				$this->logout();
				$this->sessionJustExpired = true;
				return false;
			}
			\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
			return false;
		}
	}

	/**
	 * Zjistí, zda výjimka ze SkautISu znamená vypršení přihlášení nebo neplatný token
	 */
	public function isAuthenticationError(\Throwable $e): bool
	{
		$msg = mb_strtolower($e->getMessage(), 'UTF-8');
		$authKeywords = [
			'vypršel',
			'vyprsel',
			'neplatn',
			'token',
			'není přihlášen',
			'neni prihlasen',
			'session expired',
			'authentication',
			'unauthorized',
			'accessdenied',
			'relace',
			'přihlašovací údaje',
		];

		foreach ($authKeywords as $kw) {
			if (str_contains($msg, $kw)) {
				return true;
			}
		}

		$class = get_class($e);
		if (str_contains($class, 'AuthenticationException') || str_contains($class, 'UserManagementException')) {
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

	/**
	 * Vrátí zda je povolen debug výpis rolí
	 */
	public function isDebugRoles(): bool
	{
		return $this->debugRoles;
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
	 * Získá seznam osob v aktivní jednotce ze skautISu přes MembershipAll pro naplnění Rady jednotky
	 * (výsledky jsou cachovány v session na 10 minut pro bleskovou odezvu)
	 */
	public function getUnitMembers(bool $forceRefresh = false): array
	{
		$this->lastError = null;
		if (!$this->isLoggedIn() || empty($this->session->unitId)) {
			return [];
		}

		$unitId = (int)$this->session->unitId;
		$cacheKey = 'members_' . $unitId;
		$now = time();

		// Pokud máme data v mezipaměti a nevypršela (10 minut / 600 s)
		if (!$forceRefresh && !empty($this->session->$cacheKey) && !empty($this->session->{$cacheKey . '_time'}) && ($now - $this->session->{$cacheKey . '_time'}) < 600) {
			return $this->session->$cacheKey;
		}

		// Obnovíme / ověříme přihlašovací relaci před voláním
		if (!$this->keepAlive(false) || !$this->isLoggedIn()) {
			return [];
		}

		$members = [];

		try {
			$mList = $this->skautis->org->MembershipAll([
				'ID_Unit' => $unitId,
				'IsValid' => true,
			]);

			if (is_iterable($mList)) {
				foreach ($mList as $m) {
					$pId = (int)($m->ID_Person ?? ($m->ID ?? 0));
					if ($pId <= 0 || isset($members[$pId])) {
						continue;
					}

					// Zpracování data narození
					$birthdayRaw = $m->Birthday ?? ($m->BirthDate ?? ($m->PersonBirthday ?? ($m->PersonBirthDate ?? null)));
					$birthdayFormatted = null;
					if (!empty($birthdayRaw)) {
						try {
							$dt = new \DateTime((string)$birthdayRaw);
							$birthdayFormatted = $dt->format('d. m. Y');
						} catch (\Throwable) {
							$birthdayFormatted = (string)$birthdayRaw;
						}
					}

					$members[$pId] = [
						'personId' => $pId,
						'fullName' => $m->Person ?? ($m->DisplayName ?? "Osoba #$pId"),
						'birthday' => $birthdayFormatted,
						'email' => $m->Email ?? ($m->PersonEmail ?? null),
						'membershipType' => $m->MembershipType ?? null,
					];
				}
			}
		} catch (\Throwable $e) {
			$this->lastError = 'MembershipAll: ' . $e->getMessage();
			\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
			if ($this->isAuthenticationError($e)) {
				$this->logout();
				$this->sessionJustExpired = true;
			}
		}

		$result = array_values($members);
		// Setřídíme abecedně podle jména
		usort($result, fn($a, $b) => strcmp($a['fullName'], $b['fullName']));

		// Uložíme do session cache
		if (!empty($result)) {
			$this->session->$cacheKey = $result;
			$this->session->{$cacheKey . '_time'} = $now;
		}

		return $result;
	}

	/**
	 * Získá detail osoby včetně e-mailu ze SkautISu (volá se při výběru člena)
	 */
	public function getPersonDetail(int $personId): array
	{
		if (!$this->isLoggedIn() || $personId <= 0) {
			return [];
		}

		$detail = [
			'personId' => $personId,
			'email' => null,
			'phone' => null,
			'fullName' => null,
		];

		// 1. Zkusíme org->PersonDetail
		try {
			$p = $this->skautis->org->PersonDetail(['ID' => $personId]);
			if (!empty($p)) {
				$detail['email'] = $p->Email ?? ($p->EmailDefault ?? null);
				$detail['phone'] = $p->Phone ?? ($p->PhoneDefault ?? null);
				$fullName = !empty($p->DisplayName) ? $p->DisplayName : trim(($p->FirstName ?? '') . ' ' . ($p->LastName ?? ''));
				if (!empty($p->NickName) && !str_contains($fullName, $p->NickName)) {
					$fullName .= " ({$p->NickName})";
				}
				if (!empty($fullName)) {
					$detail['fullName'] = $fullName;
				}
				if (!empty($detail['email'])) {
					return $detail;
				}
			}
		} catch (\Throwable $e) {
			\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
		}

		// 2. Zkusíme org->PersonContactAll
		try {
			$contacts = $this->skautis->org->PersonContactAll(['ID_Person' => $personId]);
			if (is_iterable($contacts)) {
				foreach ($contacts as $c) {
					$type = strtolower((string)($c->ContactType ?? ''));
					$val = trim((string)($c->Value ?? ''));
					if (empty($detail['email']) && (str_contains($type, 'email') || filter_var($val, FILTER_VALIDATE_EMAIL))) {
						$detail['email'] = $val;
					}
					if (empty($detail['phone']) && (str_contains($type, 'telefon') || str_contains($type, 'phone') || str_contains($type, 'mobil'))) {
						$detail['phone'] = $val;
					}
				}
				if (!empty($detail['email'])) {
					return $detail;
				}
			}
		} catch (\Throwable $e) {
			\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
		}

		// 3. Zkusíme user->UserDetail
		try {
			$u = $this->skautis->user->UserDetail(['ID_Person' => $personId]);
			if (!empty($u->Email)) {
				$detail['email'] = $u->Email;
			}
		} catch (\Throwable $e) {
			\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
		}

		return $detail;
	}

	/**
	 * Získá podrobný debug výpis z MembershipAll ze SkautISu
	 */
	public function debugFetchUnitMembers(?int $unitId = null): array
	{
		$targetUnitId = $unitId ?: (int)($this->session->unitId ?? 0);
		if (!$this->isLoggedIn() || empty($targetUnitId)) {
			return ['error' => 'Uživatel není přihlášen nebo nebyla zadána jednotka.'];
		}

		// Obnovíme relaci
		try {
			$this->skautis->user->LoginUpdateRefresh(['ID' => $this->session->token]);
		} catch (\Throwable $e) {
			\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
		}

		$results = [
			'unitId' => $targetUnitId,
			'session' => [
				'token' => substr((string)$this->session->token, 0, 10) . '...',
				'personId' => $this->session->personId,
				'userName' => $this->session->userName,
				'personName' => $this->session->personName,
				'roleId' => $this->session->roleId,
				'roleName' => $this->session->roleName,
				'roleKey' => $this->session->roleKey,
			],
			'variants' => [],
		];

		// MembershipAll (OrganizationUnit)
		try {
			$r3 = $this->skautis->org->MembershipAll(['ID_Unit' => $targetUnitId, 'IsValid' => true]);
			$r3Array = json_decode(json_encode($r3), true);
			if (isset($r3Array['ID'])) {
				$r3Array = [$r3Array];
			}
			$results['variants']['MembershipAll'] = [
				'service' => 'org (OrganizationUnit)',
				'operation' => 'MembershipAll',
				'params' => ['ID_Unit' => $targetUnitId, 'IsValid' => true],
				'success' => true,
				'count' => is_array($r3Array) ? count($r3Array) : (is_countable($r3) ? count($r3) : 0),
				'data' => $r3Array,
			];
		} catch (\Throwable $e) {
			$results['variants']['MembershipAll'] = [
				'service' => 'org (OrganizationUnit)',
				'operation' => 'MembershipAll',
				'params' => ['ID_Unit' => $targetUnitId, 'IsValid' => true],
				'success' => false,
				'error' => $e->getMessage(),
				'data' => null,
			];
		}

		return $results;
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
		$this->sessionManager->regenerateId();
	}
}
