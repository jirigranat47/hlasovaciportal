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
		private bool $debugRoles = false,
		private array $superadmins = []
	) {
		$this->session = $sessionManager->getSection('skautis_auth');
	}

	/**
	 * Vrátí URL pro přesměrování na přihlašovací stránku SkautISu
	 */
	public function getLoginUrl(?string $backUrl = null): string
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
						'IsActive' => true,
					]);

					// Fallback: pokud by uživatel neměl žádnou roli označenou jako aktivní, načteme všechny
					if (empty($roles)) {
						$roles = $this->skautis->user->UserRoleAll([
							'ID_User' => $userDetail->ID,
						]);
					}

					// Převedeme na čisté asociativní pole se všemi parametry
					$rolesArray = json_decode(json_encode($roles), true);
					if (isset($rolesArray['ID'])) {
						// Pokud byla vrácena pouze jedna role (jako asociativní pole místo pole rolí)
						$rolesArray = [$rolesArray];
					}
					$rolesArray = $rolesArray ?: [];

					// Tracy debug výpis (pokud je zapnut v neon konfiguraci: skautis.debugRoles)
					if ($this->debugRoles) {
						\Tracy\Debugger::barDump($rolesArray, 'SkautIS - Aktivní role uživatele (UserRoleAll IsActive=true)');
						\Tracy\Debugger::log($rolesArray, 'user-roles');
					}

					// Omezíme seznam nabízených rolí pouze na Kraj, Okres, Středisko (vedoucí/admin či aktivní činovník)
					$allowedRoles = self::filterAllowedRoles($rolesArray);
					$this->session->allRoles = $allowedRoles;

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

					// Pokud uživatel přišel ze SkautISu s nepovolenou rolí (např. oddíl či tábor), ale má povolenou roli, automaticky přepneme
					$this->ensureAllowedRole();
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
	 * Zkontroluje, zda daná role patří mezi povolené (Kraj, Okres, Středisko a to vedoucí/admin či aktivní činovník)
	 */
	public static function isAllowedRole(array $role): bool
	{
		$key = strtolower(trim((string)($role['Key'] ?? '')));
		$allowedKeys = [
			'vedoucikraj',
			'vedoucikraje',
			'cinovnikkraj',
			'cinovnikkraje',
			'vedouciokres',
			'vedouciokresu',
			'cinovnikokres',
			'cinovnikokresu',
			'vedoucistredisko',
			'vedoucistrediska',
			'cinovnikstredisko',
			'cinovnikstrediska',
		];
		if ($key !== '' && in_array($key, $allowedKeys, true)) {
			return true;
		}

		$roleName = trim((string)($role['Role'] ?? ($role['DisplayName'] ?? '')));

		// Formát ze SkautISu "Jednotka: Role", např. "Středisko: vedoucí/admin", "Kraj: aktivní činovník", "Okres: vedoucí/admin"
		if (preg_match('/^(kraj|okres|středisko|stredisko)\s*:\s*(vedoucí\/admin|vedouci\/admin|aktivní činovník|aktivni cinovnik)/ui', $roleName)) {
			return true;
		}

		// Alternativní české názvy např. v testech nebo dev simulacích: "Vedoucí střediska", "Vedoucí kraje", "Aktivní činovník okresu"
		if (preg_match('/^(vedoucí|vedouci|aktivní činovník|aktivni cinovnik|činovník|cinovnik)\s+(kraje|okresu|střediska|strediska)/ui', $roleName)) {
			return true;
		}

		return false;
	}

	/**
	 * Vrátí klíč pro uspořádání rolí: Kraj -> Okres -> Středisko, uvnitř vedoucí/admin -> aktivní činovník, pak abecedně dle jednotky
	 */
	public static function getRoleSortKey(array $role): string
	{
		$key = strtolower(trim((string)($role['Key'] ?? '')));
		$roleName = mb_strtolower(trim((string)($role['Role'] ?? ($role['DisplayName'] ?? ''))), 'UTF-8');

		$unitPriority = 9;
		if (str_contains($key, 'kraj') || str_contains($roleName, 'kraj')) {
			$unitPriority = 1;
		} elseif (str_contains($key, 'okres') || str_contains($roleName, 'okres')) {
			$unitPriority = 2;
		} elseif (str_contains($key, 'stredisko') || str_contains($roleName, 'středisko') || str_contains($roleName, 'stredisko')) {
			$unitPriority = 3;
		}

		$typePriority = 9;
		if (str_contains($key, 'vedouci') || str_contains($roleName, 'vedoucí') || str_contains($roleName, 'vedouci') || str_contains($roleName, 'admin')) {
			$typePriority = 1;
		} elseif (str_contains($key, 'cinovnik') || str_contains($roleName, 'činovník') || str_contains($roleName, 'cinovnik')) {
			$typePriority = 2;
		}

		$unitName = (string)($role['Unit'] ?? '');

		return sprintf('%d_%d_%s', $unitPriority, $typePriority, $unitName);
	}

	/**
	 * Vyfiltruje a uspořádá pouze povolené role (Kraj, Okres, Středisko a to vedoucí/admin či aktivní činovník)
	 */
	public static function filterAllowedRoles(array $roles): array
	{
		$filtered = [];
		foreach ($roles as $role) {
			if (is_array($role) && self::isAllowedRole($role)) {
				$filtered[] = $role;
			}
		}

		usort($filtered, fn($a, $b) => strcmp(self::getRoleSortKey($a), self::getRoleSortKey($b)));

		return $filtered;
	}

	/**
	 * Zajistí, že pokud má uživatel k dispozici povolené role (Kraj/Okres/Středisko),
	 * je aktivní role nastavena na jednu z nich (pokud byl dosud v nepovolené roli, např. oddíl či tábor).
	 */
	public function ensureAllowedRole(): void
	{
		if (!$this->isLoggedIn()) {
			return;
		}

		$allowedRoles = $this->getAllUserRoles();
		if (empty($allowedRoles)) {
			return;
		}

		$currentRoleId = (int)($this->session->roleId ?? 0);
		foreach ($allowedRoles as $role) {
			if (((int)($role['ID'] ?? 0) === $currentRoleId) || ((int)($role['ID_Role'] ?? 0) === $currentRoleId)) {
				return;
			}
		}

		// Současná role není v povolených rolích, přepneme na preferovanou povolenou roli (ideálně vedoucí/admin)
		$preferredRole = null;
		foreach ($allowedRoles as $role) {
			$key = strtolower((string)($role['Key'] ?? ''));
			if (str_contains($key, 'vedouci')) {
				$preferredRole = $role;
				break;
			}
		}
		$targetRole = $preferredRole ?? $allowedRoles[0];
		$targetRoleId = (int)($targetRole['ID'] ?? ($targetRole['ID_Role'] ?? 0));
		if ($targetRoleId > 0) {
			$this->switchRole($targetRoleId);
		}
	}

	/**
	 * Vrátí povolené role uživatele ze SkautISu (Kraj, Okres, Středisko a to vedoucí/admin či aktivní činovník)
	 */
	public function getAllUserRoles(): array
	{
		$roles = $this->session->allRoles ?? [];
		return self::filterAllowedRoles($roles);
	}

	/**
	 * Vrátí zda je povolen debug výpis rolí
	 */
	public function isDebugRoles(): bool
	{
		return $this->debugRoles;
	}

	/**
	 * Vrátí zda běží v testovacím režimu
	 */
	public function isTest(): bool
	{
		return $this->isTest;
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
			if ((int)($role['ID'] ?? 0) === $userRoleId || ((int)($role['ID'] ?? 0) === 0 && (int)($role['ID_Role'] ?? 0) === $userRoleId)) {
				$roleId = (int)$role['ID'];
				$unitId = isset($role['ID_Unit']) ? (int)$role['ID_Unit'] : null;

				$this->session->roleId = $roleId;
				$this->session->unitId = $unitId;
				$this->session->roleName = $role['Role'] ?? ($role['DisplayName'] ?? '');
				$this->session->roleKey = $role['Key'] ?? '';
				$this->session->unitName = $role['Unit'] ?? '';

				// 1. Přepneme roli přímo na serveru SkautISu (pouze pokud nejde o testovací simulaci)
				if ($this->session->token !== 'mock_token') {
					try {
						$this->skautis->user->LoginUpdate([
							'ID' => $this->session->token,
							'ID_UserRole' => $roleId,
						]);
					} catch (\Throwable $e) {
						\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
					}
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
	 * Ověří, zda je aktuálně přihlášený uživatel superadministrátorem aplikace (dle Person ID)
	 */
	public function isSuperAdmin(): bool
	{
		if (!$this->isLoggedIn()) {
			return false;
		}

		$personId = (int)($this->session->personId ?? 0);
		if ($personId <= 0 || empty($this->superadmins)) {
			return false;
		}

		return in_array($personId, array_map('intval', $this->superadmins), true);
	}

	/**
	 * Vrátí pole Person ID všech superadministrátorů
	 */
	public function getSuperAdminPersonIds(): array
	{
		return array_map('intval', $this->superadmins);
	}

	/**
	 * Pomocná metoda pro extrakci údajů o členovi ze záznamu SkautISu
	 */
	private function extractMemberData(mixed $m, array &$members): void
	{
		if (!is_object($m) && !is_array($m)) {
			return;
		}
		$mObj = (object)$m;
		$pId = (int)($mObj->ID_Person ?? ($mObj->ID ?? 0));
		if ($pId <= 0 || isset($members[$pId])) {
			return;
		}

		// Zpracování data narození
		$birthdayRaw = $mObj->Birthday ?? ($mObj->BirthDate ?? ($mObj->PersonBirthday ?? ($mObj->PersonBirthDate ?? null)));
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
			'fullName' => $mObj->Person ?? ($mObj->DisplayName ?? "Osoba #$pId"),
			'birthday' => $birthdayFormatted,
			'email' => $mObj->Email ?? ($mObj->PersonEmail ?? null),
			'membershipType' => $mObj->MembershipType ?? null,
		];
	}

	/**
	 * Získá seznam osob v aktivní jednotce ze skautISu přes MembershipAll pro naplnění Rady jednotky.
	 * Pokud jednotka (např. Kraj) přesahuje limit 1 000 osob, automaticky dotáhne všechny zbývající členy rozpadem přes abecedu.
	 * (výsledky jsou cachovány v session na 30 minut pro bleskovou odezvu)
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

		// Pokud máme data v mezipaměti a nevypršela (30 minut / 1800 s)
		if (!$forceRefresh && !empty($this->session->$cacheKey) && !empty($this->session->{$cacheKey . '_time'}) && ($now - $this->session->{$cacheKey . '_time'}) < 1800) {
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

			$initialCount = 0;
			if (is_iterable($mList)) {
				foreach ($mList as $m) {
					$initialCount++;
					$this->extractMemberData($m, $members);
				}
			}

			// Zaznamenáme, zda byl výsledek oříznut limitem 1 000 osob (kraje/velké jednotky)
			$this->session->{$cacheKey . '_truncated'} = ($initialCount >= 1000);
		} catch (\Throwable $e) {
			$this->lastError = 'MembershipAll: ' . $e->getMessage();
			if (!empty(\Tracy\Debugger::$logDirectory)) {
				\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
			}
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
	 * Zjistí, zda přednačtený seznam členů jednotky přesáhl limit 1 000 osob
	 */
	public function isUnitMembersTruncated(): bool
	{
		$unitId = (int)($this->session->unitId ?? 0);
		$cacheKey = 'members_' . $unitId . '_truncated';
		return !empty($this->session->$cacheKey);
	}

	/**
	 * Alias pro getUnitMembers() pro načtení osob ze SkautISu pro výběr členů Rady
	 */
	public function getCouncilMembers(bool $forceRefresh = false): array
	{
		return $this->getUnitMembers($forceRefresh);
	}

	/**
	 * Bleskově vyhledá členy jednotky ze SkautISu podle příjmení, jména nebo Person ID.
	 * Umožňuje administrátorům krajů i velkých jednotek okamžitý přístup ke všem osobám bez prodlev a timeoutů.
	 */
	public function searchUnitMembers(string $query): array
	{
		$trimmed = trim($query);
		if (!$this->isLoggedIn() || empty($this->session->unitId) || $trimmed === '') {
			return [];
		}

		$unitId = (int)$this->session->unitId;

		// 1. Pokud je dotaz číslo, zkusíme přímo detail osoby podle Person ID
		if (ctype_digit($trimmed)) {
			$pId = (int)$trimmed;
			$detail = $this->getPersonDetail($pId);
			if (!empty($detail['fullName'])) {
				return [[
					'personId' => $pId,
					'fullName' => $detail['fullName'],
					'birthday' => null,
					'email' => $detail['email'] ?? null,
					'membershipType' => null,
				]];
			}
		}

		// 2. Vyhledání přes MembershipAll podle příjmení (LastName)
		// Pokud uživatel zadal např. "Jan Novák" nebo "Novák Jan", vyzkoušíme jednotlivá slova jako příjmení
		$words = preg_split('/\s+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
		$surnamesToTry = [];
		if (count($words) === 1) {
			$surnamesToTry[] = $words[0];
		} else {
			// Zkusíme poslední slovo (často příjmení: "Jan Novák") i první slovo ("Novák Jan")
			$surnamesToTry[] = end($words);
			$surnamesToTry[] = reset($words);
			$surnamesToTry = array_unique($surnamesToTry);
		}

		// Přidáme i variantu s velkým počátečním písmenem pro případ, že uživatel zadal malé písmena
		$candidates = [];
		foreach ($surnamesToTry as $s) {
			$candidates[] = $s;
			$title = mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
			if ($title !== $s) {
				$candidates[] = $title;
			}
		}
		$surnamesToTry = array_values(array_unique($candidates));

		$temp = [];
		foreach ($surnamesToTry as $lastName) {
			if (mb_strlen($lastName) < 2) {
				continue;
			}
			try {
				$mList = $this->skautis->org->MembershipAll([
					'ID_Unit' => $unitId,
					'IsValid' => true,
					'LastName' => $lastName,
				]);

				if (is_iterable($mList)) {
					foreach ($mList as $m) {
						$this->extractMemberData($m, $temp);
					}
				}
			} catch (\Throwable $e) {
				if (!empty(\Tracy\Debugger::$logDirectory)) {
					\Tracy\Debugger::log($e, \Tracy\ILogger::WARNING);
				}
			}
		}

		// Pokud uživatel zadal více slov, ověříme aby jméno obsahovalo všechna zadaná slova
		if (count($words) > 1 && !empty($temp)) {
			$filtered = [];
			foreach ($temp as $pId => $m) {
				$matchAll = true;
				$lowerFullName = mb_strtolower($m['fullName']);
				foreach ($words as $w) {
					if (!str_contains($lowerFullName, mb_strtolower($w))) {
						$matchAll = false;
						break;
					}
				}
				if ($matchAll) {
					$filtered[$pId] = $m;
				}
			}
			$temp = $filtered;
		}

		$results = array_values($temp);
		usort($results, fn($a, $b) => strcmp($a['fullName'], $b['fullName']));

		return $results;
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
	 * Simuluje přihlášení pro vývoj a testování (výchozí jako administrátor / vedoucí střediska)
	 */
	public function simulateLogin(
		int $unitId = 10001,
		int $personId = 1,
		string $personName = 'Admin Testovací',
		string $roleKey = 'vedouciStredisko',
		string $roleName = 'Vedoucí střediska',
		string $unitName = 'Testovací středisko',
		?array $roles = null
	): void {
		$this->session->token = 'mock_token';
		$this->session->personId = $personId;
		$this->session->userName = 'admin_mock';
		$this->session->personName = $personName;

		if ($roles !== null) {
			$allRoles = $roles;
		} elseif ($personId === 1001) {
			// Jan Novák - testovací uživatel s rolemi ve více jednotkách (středisko, okres, kraj)
			$allRoles = [
				[
					'ID' => 10011,
					'ID_Role' => 10011,
					'ID_Unit' => 123,
					'Key' => 'cinovnikStredisko',
					'Role' => 'Středisko: aktivní činovník',
					'DisplayName' => 'Středisko: aktivní činovník',
					'Unit' => '1. středisko Dvojka',
				],
				[
					'ID' => 10012,
					'ID_Role' => 10012,
					'ID_Unit' => 456,
					'Key' => 'vedouciOkres',
					'Role' => 'Okres: vedoucí/admin',
					'DisplayName' => 'Okres: vedoucí/admin',
					'Unit' => 'Okres Hradec Králové',
				],
				[
					'ID' => 10013,
					'ID_Role' => 10013,
					'ID_Unit' => 25784,
					'Key' => 'cinovnikKraj',
					'Role' => 'Kraj: aktivní činovník',
					'DisplayName' => 'Kraj: aktivní činovník',
					'Unit' => 'Královéhradecký kraj',
				],
			];
		} elseif ($personId === 9999) {
			// Admin Zakladatel - administrátor s rolemi ve více jednotkách
			$allRoles = [
				[
					'ID' => 99991,
					'ID_Role' => 99991,
					'ID_Unit' => 123,
					'Key' => 'vedouciStredisko',
					'Role' => 'Středisko: vedoucí/admin',
					'DisplayName' => 'Středisko: vedoucí/admin',
					'Unit' => '1. středisko Dvojka',
				],
				[
					'ID' => 99992,
					'ID_Role' => 99992,
					'ID_Unit' => 456,
					'Key' => 'cinovnikOkres',
					'Role' => 'Okres: aktivní činovník',
					'DisplayName' => 'Okres: aktivní činovník',
					'Unit' => 'Okres Hradec Králové',
				],
				[
					'ID' => 99993,
					'ID_Role' => 99993,
					'ID_Unit' => 25784,
					'Key' => 'vedouciKraj',
					'Role' => 'Kraj: vedoucí/admin',
					'DisplayName' => 'Kraj: vedoucí/admin',
					'Unit' => 'Královéhradecký kraj',
				],
			];
		} elseif ($personId === 1 && $unitId === 10001) {
			// Superadmin Aplikace (lokální vývoj - více jednotek)
			$allRoles = [
				[
					'ID' => 100011,
					'ID_Role' => 100011,
					'ID_Unit' => 10001,
					'Key' => 'vedouciStredisko',
					'Role' => 'Středisko: vedoucí/admin',
					'DisplayName' => 'Středisko: vedoucí/admin',
					'Unit' => 'Středisko Superadmin',
				],
				[
					'ID' => 100012,
					'ID_Role' => 100012,
					'ID_Unit' => 123,
					'Key' => 'cinovnikStredisko',
					'Role' => 'Středisko: aktivní činovník',
					'DisplayName' => 'Středisko: aktivní činovník',
					'Unit' => '1. středisko Dvojka',
				],
				[
					'ID' => 100013,
					'ID_Role' => 100013,
					'ID_Unit' => 25784,
					'Key' => 'vedouciKraj',
					'Role' => 'Kraj: vedoucí/admin',
					'DisplayName' => 'Kraj: vedoucí/admin',
					'Unit' => 'Královéhradecký kraj',
				],
			];
		} else {
			$allRoles = [
				[
					'ID' => 12345,
					'ID_Role' => 12345,
					'ID_Unit' => $unitId,
					'Key' => $roleKey,
					'Role' => $roleName,
					'DisplayName' => $roleName,
					'Unit' => $unitName,
				]
			];
		}

		$filteredRoles = self::filterAllowedRoles($allRoles);
		$this->session->allRoles = $filteredRoles;

		// Najdeme roli odpovídající zvolené jednotce $unitId
		$activeRole = null;
		foreach ($filteredRoles as $r) {
			if ((int)($r['ID_Unit'] ?? 0) === $unitId) {
				$activeRole = $r;
				break;
			}
		}

		if ($activeRole !== null) {
			$this->session->roleId = (int)$activeRole['ID'];
			$this->session->unitId = (int)$activeRole['ID_Unit'];
			$this->session->unitName = $activeRole['Unit'] ?? $unitName;
			$this->session->roleName = $activeRole['Role'] ?? $roleName;
			$this->session->roleKey = $activeRole['Key'] ?? $roleKey;
		} else {
			$this->session->roleId = 12345;
			$this->session->unitId = $unitId;
			$this->session->unitName = $unitName;
			$this->session->roleName = $roleName;
			$this->session->roleKey = $roleKey;
		}
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
