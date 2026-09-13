<?php

declare(strict_types=1);

namespace App\Model;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;

class VotingRepository
{
	public function __construct(
		private Explorer $database,
		private string $encryptionKey,
		private int $defaultMinVotingDurationHours = 48
	) {}

	/**
	 * Vrátí výchozí minimální počet hodin trvání hlasování ze systémové konfigurace
	 */
	public function getDefaultMinVotingDuration(): int
	{
		return max(1, $this->defaultMinVotingDurationHours);
	}

	/**
	 * Vrátí minimální počet hodin trvání hlasování pro konkrétní jednotku
	 */
	public function getUnitMinVotingDuration(int $unitId): int
	{
		$settings = $this->getUnitSettings($unitId);
		if ($settings && !empty($settings->min_voting_duration_hours)) {
			return max(1, (int)$settings->min_voting_duration_hours);
		}
		return $this->getDefaultMinVotingDuration();
	}

	/**
	 * Zkontroluje, zda je osoba členem rady jednotky
	 */
	public function isCouncilMember(int $unitId, int $personId): bool
	{
		return $this->database->table('council_members')
			->where('unit_id', $unitId)
			->where('person_id', $personId)
			->count() > 0;
	}

	/**
	 * Vrátí všechny členy rady dané jednotky
	 */
	public function getCouncilMembers(int $unitId): array
	{
		return $this->database->table('council_members')
			->where('unit_id', $unitId)
			->order('full_name ASC')
			->fetchAll();
	}

	/**
	 * Přidá člena do rady jednotky
	 */
	public function addCouncilMember(int $unitId, int $personId, string $fullName, ?string $email): void
	{
		$this->database->table('council_members')->insert([
			'unit_id' => $unitId,
			'person_id' => $personId,
			'full_name' => $fullName,
			'email' => $email,
			'created_at' => new \DateTime(),
		]);
	}

	/**
	 * Odebere člena z rady jednotky
	 */
	public function removeCouncilMember(int $unitId, int $personId): void
	{
		$this->database->table('council_members')
			->where('unit_id', $unitId)
			->where('person_id', $personId)
			->delete();
	}

	public function encryptPassword(string $plain): string
	{
		if (empty($plain)) {
			return '';
		}
		// Pokud už je zašifrováno
		if (str_starts_with($plain, 'ENC:')) {
			return $plain;
		}
		$key = hash('sha256', $this->encryptionKey, true);
		$iv = openssl_random_pseudo_bytes(16);
		$encrypted = openssl_encrypt($plain, 'AES-256-CBC', $key, 0, $iv);
		return 'ENC:' . base64_encode($iv . '::' . $encrypted);
	}

	public function decryptPassword(?string $encoded): string
	{
		if (empty($encoded)) {
			return '';
		}
		if (!str_starts_with($encoded, 'ENC:')) {
			// Pro zpětnou kompatibilitu s nezašifrovaným plain textem
			return $encoded;
		}
		$raw = substr($encoded, 4);
		$decoded = base64_decode($raw, true);
		if ($decoded === false || !str_contains($decoded, '::')) {
			return $encoded;
		}
		[$iv, $encrypted] = explode('::', $decoded, 2);

		// 1. Zkusíme dešifrovat aktuálně nastaveným klíčem
		$key = hash('sha256', $this->encryptionKey, true);
		$decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
		if ($decrypted !== false) {
			return $decrypted;
		}

		// 2. Zpětná kompatibilita pro hesla uložená před zavedením konfigurovatelného klíče
		$legacyKeys = [
			hash('sha256', '', true),
			hash('sha256', 'skaut_hlasovaci_portal_default_secret_key_v1', true),
			hash('sha256', 'skaut_hlasovaci_portal_smtp_secret_key_v1', true),
		];
		foreach ($legacyKeys as $legacyKey) {
			$legacyDecrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $legacyKey, 0, $iv);
			if ($legacyDecrypted !== false) {
				return $legacyDecrypted;
			}
		}

		return '';
	}

	/**
	 * Získá SMTP nastavení pro danou jednotku
	 */
	public function getSmtpSettings(int $unitId): ?ActiveRow
	{
		$row = $this->database->table('smtp_settings')->get($unitId);
		if (!$row) {
			return null;
		}

		if (!empty($row->password)) {
			try {
				$decrypted = $this->decryptPassword($row->password);
				$row->offsetSet('password', $decrypted);
			} catch (\Throwable $e) {
				// Pokud dešifrování selže, ponecháme původní
			}
		}

		return $row;
	}

	/**
	 * Uloží SMTP nastavení pro jednotku (s šifrováním hesla)
	 */
	public function saveSmtpSettings(int $unitId, array $values): void
	{
		$row = $this->database->table('smtp_settings')->get($unitId);

		$password = $values['password'] ?? '';
		if ($password === '' && $row) {
			$password = $row->password;
		} else {
			$password = $this->encryptPassword($password);
		}

		$data = [
			'host' => $values['host'],
			'port' => (int)$values['port'],
			'username' => $values['username'],
			'password' => $password,
			'secure' => $values['secure'],
			'from_email' => $values['from_email'],
			'from_name' => $values['from_name'],
		];

		if ($row) {
			$row->update($data);
		} else {
			$data['unit_id'] = $unitId;
			$this->database->table('smtp_settings')->insert($data);
		}
	}

	/**
	 * Získá obecné nastavení pro danou jednotku
	 */
	public function getUnitSettings(int $unitId): ?ActiveRow
	{
		return $this->database->table('unit_settings')->get($unitId);
	}

	/**
	 * Uloží obecné nastavení pro jednotku
	 */
	public function saveUnitSettings(int $unitId, array $values): void
	{
		$row = $this->database->table('unit_settings')->get($unitId);
		$minHours = !empty($values['min_voting_duration_hours']) ? max(1, (int)$values['min_voting_duration_hours']) : null;
		$fromName = !empty($values['from_name']) ? trim((string)$values['from_name']) : null;

		$data = [
			'allow_custom_end_time' => !empty($values['allow_custom_end_time']) ? 1 : 0,
			'min_voting_duration_hours' => $minHours,
			'from_name' => $fromName,
		];

		if ($row) {
			$row->update($data);
		} else {
			$data['unit_id'] = $unitId;
			$this->database->table('unit_settings')->insert($data);
		}
	}

	/**
	 * Vrátí efektivní jméno odesílatele pro e-maily jednotky
	 */
	public function getUnitEmailFromName(int $unitId, ?string $fallbackUnitName = null): string
	{
		$settings = $this->getUnitSettings($unitId);
		if ($settings && !empty($settings->from_name)) {
			return (string)$settings->from_name;
		}

		$unitName = $fallbackUnitName ?: $this->getUnitName($unitId);
		if (!empty($unitName)) {
			return "Rada {$unitName}";
		}

		return 'Skautský Hlasovací Portál';
	}

	/**
	 * Získá název jednotky (z historie přihlášení uživatelů)
	 */
	public function getUnitName(int $unitId): ?string
	{
		$row = $this->database->table('user_login_logs')
			->where('unit_id', $unitId)
			->where('unit_name IS NOT NULL')
			->order('id DESC')
			->fetch();

		if ($row && !empty($row->unit_name)) {
			return $row->unit_name;
		}

		return null;
	}

	/**
	 * Vrátí konkrétní hlasování
	 */
	public function getElection(int $id): ?ActiveRow
	{
		return $this->database->table('elections')->get($id);
	}

	/**
	 * Vrátí možnosti pro hlasování
	 */
	public function getOptions(int $electionId): array
	{
		return $this->database->table('options')
			->where('election_id', $electionId)
			->fetchAll();
	}

	/**
	 * Zkontroluje, zda daná osoba již hlasovala
	/**
	 * Vrátí aktuální hlas dané osoby v hlasování
	 */
	public function getUserVote(int $electionId, int $personId): ?ActiveRow
	{
		return $this->database->table('votes')
			->where('election_id', $electionId)
			->where('person_id', $personId)
			->fetch();
	}

	/**
	 * Zkontroluje, zda daná osoba již hlasovala
	 */
	public function hasVoted(int $electionId, int $personId): bool
	{
		return $this->getUserVote($electionId, $personId) !== null;
	}

	/**
	 * Odevzdá nebo změní hlas (s evidencí historie a auditu)
	 */
	public function vote(int $electionId, int $optionId, int $personId, string $personName, ?string $roleName = null): bool
	{
		$targetOption = $this->database->table('options')->get($optionId);
		if (!$targetOption || (int)$targetOption->election_id !== $electionId) {
			return false;
		}

		$existingVote = $this->getUserVote($electionId, $personId);
		$now = new \DateTime();

		$this->database->beginTransaction();
		try {
			if ($existingVote) {
				// Pokud hlasoval pro stejnou možnost, nic neměníme
				if ((int)$existingVote->option_id === $optionId) {
					$this->database->commit();
					return true;
				}

				$oldOptionId = (int)$existingVote->option_id;

				// Dekrementujeme starou možnost
				$this->database->table('options')
					->where('id', $oldOptionId)
					->update([
						'votes_count' => $this->database->literal('GREATEST(0, votes_count - 1)'),
					]);

				// Inkrementujeme novou možnost
				$this->database->table('options')
					->where('id', $optionId)
					->update([
						'votes_count' => $this->database->literal('votes_count + 1'),
					]);

				// Aktualizujeme existující hlas
				$existingVote->update([
					'option_id' => $optionId,
					'person_name' => $personName,
					'created_at' => $now,
				]);

				// Záznam do historie změny hlasování
				$this->database->table('vote_history')->insert([
					'election_id' => $electionId,
					'person_id' => $personId,
					'person_name' => $personName,
					'option_id' => $optionId,
					'option_title' => $targetOption->title,
					'action' => 'changed',
					'created_at' => $now,
				]);

				// Auditní záznam
				$this->logElectionAudit(
					$electionId,
					$personId,
					$personName,
					$roleName,
					'vote_changed',
					"Změněn hlas na: '{$targetOption->title}'"
				);

			} else {
				// Vložíme nový hlas
				$this->database->table('votes')->insert([
					'election_id' => $electionId,
					'person_id' => $personId,
					'person_name' => $personName,
					'option_id' => $optionId,
					'created_at' => $now,
				]);

				// Inkrementujeme počítadlo
				$this->database->table('options')
					->where('id', $optionId)
					->update([
						'votes_count' => $this->database->literal('votes_count + 1'),
					]);

				// Záznam do historie nového hlasu
				$this->database->table('vote_history')->insert([
					'election_id' => $electionId,
					'person_id' => $personId,
					'person_name' => $personName,
					'option_id' => $optionId,
					'option_title' => $targetOption->title,
					'action' => 'voted',
					'created_at' => $now,
				]);

				// Auditní záznam
				$this->logElectionAudit(
					$electionId,
					$personId,
					$personName,
					$roleName,
					'vote_cast',
					"Odevzdán hlas: '{$targetOption->title}'"
				);
			}

			$this->database->commit();
			return true;
		} catch (\Throwable $e) {
			$this->database->rollBack();
			return false;
		}
	}

	/**
	 * Zkontroluje, zda číslo usnesení v dané jednotce již existuje
	 */
	public function isResolutionNumberExists(int $unitId, string $resolutionNumber, ?int $excludeElectionId = null): bool
	{
		$query = $this->database->table('elections')
			->where('unit_id', $unitId)
			->where('resolution_number', trim($resolutionNumber));

		if ($excludeElectionId !== null) {
			$query->where('id !=', $excludeElectionId);
		}

		return $query->count() > 0;
	}

	/**
	 * Založí nové hlasování a vytvoří standardní možnosti Pro, Proti, Zdržel se
	 */
	public function createElection(
		array $values,
		int $unitId,
		int $createdByPersonId,
		?string $personName = null,
		?string $roleName = null
	): ActiveRow {
		$this->database->beginTransaction();
		try {
			$endDate = new \DateTime($values['end_date']);
			if (!empty($values['end_time']) && preg_match('/^(\d{1,2}):(\d{2})$/', trim($values['end_time']), $m)) {
				$endDate->setTime((int)$m[1], (int)$m[2], 0);
			} elseif (!str_contains($values['end_date'], ' ') && !str_contains($values['end_date'], 'T')) {
				$endDate->setTime(23, 59, 59);
			}

			$election = $this->database->table('elections')->insert([
				'resolution_number' => trim($values['resolution_number']),
				'title' => $values['title'],
				'description' => $values['description'] ?? null,
				'unit_id' => $unitId,
				'status' => 'draft',
				'proposal_received_date' => !empty($values['proposal_received_date']) ? new \DateTime($values['proposal_received_date']) : null,
				'end_date' => $endDate,
				'created_by_person_id' => $createdByPersonId,
				'created_at' => new \DateTime(),
			]);

			// Automaticky vytvoříme možnosti
			$this->database->table('options')->insert([
				['election_id' => $election->id, 'title' => 'Pro'],
				['election_id' => $election->id, 'title' => 'Proti'],
				['election_id' => $election->id, 'title' => 'Zdržel se'],
			]);

			// Auditní záznam
			$formattedTime = $endDate->format('H:i') === '23:59' ? $endDate->format('d. m. Y (23:59)') : $endDate->format('d. m. Y H:i');
			$this->logElectionAudit(
				(int)$election->id,
				$createdByPersonId,
				$personName ?? "Osoba #$createdByPersonId",
				$roleName,
				'created_draft',
				"Vytvořen návrh usnesení č. {$values['resolution_number']} (Draft). Termín hlasování nastaven do {$formattedTime}."
			);

			$this->database->commit();
			return $election;
		} catch (\Throwable $e) {
			$this->database->rollBack();
			throw $e;
		}
	}

	/**
	 * Aktualizuje existující hlasování (pouze pokud je draft)
	 */
	public function updateElection(
		int $id,
		array $values,
		int $personId,
		string $personName,
		?string $roleName = null
	): void {
		$election = $this->getElection($id);
		if ($election && $election->status === 'draft') {
			$endDate = new \DateTime($values['end_date']);
			if (!empty($values['end_time']) && preg_match('/^(\d{1,2}):(\d{2})$/', trim($values['end_time']), $m)) {
				$endDate->setTime((int)$m[1], (int)$m[2], 0);
			} elseif (!str_contains($values['end_date'], ' ') && !str_contains($values['end_date'], 'T')) {
				$endDate->setTime(23, 59, 59);
			}

			$changes = [];
			if ($election->resolution_number !== trim($values['resolution_number'])) {
				$changes[] = "číslo usnesení z '{$election->resolution_number}' na '" . trim($values['resolution_number']) . "'";
			}
			if ($election->title !== $values['title']) {
				$changes[] = "text usnesení";
			}
			if ($election->end_date->format('Y-m-d H:i') !== $endDate->format('Y-m-d H:i')) {
				$formattedTime = $endDate->format('H:i') === '23:59' ? $endDate->format('d. m. Y (23:59)') : $endDate->format('d. m. Y H:i');
				$changes[] = "termín konce na " . $formattedTime;
			}

			$election->update([
				'resolution_number' => trim($values['resolution_number']),
				'title' => $values['title'],
				'description' => $values['description'] ?? null,
				'proposal_received_date' => !empty($values['proposal_received_date']) ? new \DateTime($values['proposal_received_date']) : null,
				'end_date' => $endDate,
			]);

			$details = !empty($changes) ? "Upraveny údaje: " . implode(', ', $changes) . "." : "Upraveny podklady / text návrhu usnesení.";
			$this->logElectionAudit($id, $personId, $personName, $roleName, 'updated_draft', $details);
		}
	}

	/**
	 * Přepne hlasování do stavu Published
	 */
	public function publishElection(int $id, int $personId, string $personName, ?string $roleName = null): void
	{
		$election = $this->getElection($id);
		if ($election && $election->status === 'draft') {
			$election->update([
				'status' => 'published',
				'notification_sent' => 0, // Resetujeme příznak notifikace, aby se mohla odeslat nová výzva k upravenému znění
				'reminder_sent' => 0,
				'results_sent' => 0,
			]);

			$this->logElectionAudit(
				$id,
				$personId,
				$personName,
				$roleName,
				'published',
				"Usnesení bylo publikováno a zahájeno hlasování (do {$election->end_date->format('d. m. Y H:i')})."
			);
		}
	}

	/**
	 * Zjistí, zda lze publikované hlasování vrátit do draftu (pouze pokud dosud nikdo nehlasoval)
	 */
	public function canRevertToDraft(int $id): bool
	{
		$election = $this->getElection($id);
		if (!$election || $election->status !== 'published') {
			return false;
		}

		$votesCount = $this->database->table('votes')->where('election_id', $id)->count();
		return $votesCount === 0;
	}

	/**
	 * Vrátí publikované usnesení zpět do stavu Draft (pokud nikdo nehlasoval)
	 */
	public function revertToDraft(int $id, int $personId, string $personName, ?string $roleName = null): bool
	{
		if (!$this->canRevertToDraft($id)) {
			return false;
		}

		$election = $this->getElection($id);
		$election->update([
			'status' => 'draft',
		]);

		$this->logElectionAudit(
			$id,
			$personId,
			$personName,
			$roleName,
			'reverted_to_draft',
			"Usnesení bylo vráceno z publikovaného stavu zpět do režimu návrhu (Draft), protože dosud nikdo nehlasoval. Nyní je možné text a podklady upravit."
		);

		return true;
	}

	/**
	 * Smaže hlasování (pouze pokud je draft)
	 */
	public function deleteElection(int $id): void
	{
		$election = $this->getElection($id);
		if ($election && $election->status === 'draft') {
			$election->delete();
		}
	}

	/**
	 * Stornuje publikované hlasování
	 */
	public function cancelElection(
		int $id,
		string $reason,
		int $cancelledByPersonId,
		string $personName,
		?string $roleName = null,
		array $sentEmails = []
	): void {
		$election = $this->getElection($id);
		if ($election && $election->status === 'published') {
			$election->update([
				'status' => 'cancelled',
				'cancellation_reason' => trim($reason),
				'cancelled_at' => new \DateTime(),
				'cancelled_by_person_id' => $cancelledByPersonId,
			]);

			$details = "Hlasování bylo stornováno. Důvod: " . trim($reason);
			if (!empty($sentEmails)) {
				$details .= " | E-mailové upozornění odesláno na adresy (" . count($sentEmails) . "): " . implode(', ', $sentEmails);
			} else {
				$details .= " | (E-mailová notifikace neodešla - chybí SMTP nebo e-maily členů)";
			}

			$this->logElectionAudit(
				$id,
				$cancelledByPersonId,
				$personName,
				$roleName,
				'cancelled',
				$details
			);
		}
	}

	/**
	 * Zapíše auditní záznam o změně usnesení / hlasování
	 */
	public function logElectionAudit(
		int $electionId,
		int $personId,
		string $personName,
		?string $roleName,
		string $action,
		?string $details = null
	): void {
		$this->database->table('election_audit_logs')->insert([
			'election_id' => $electionId,
			'person_id' => $personId,
			'person_name' => $personName,
			'role_name' => $roleName,
			'action' => $action,
			'details' => $details,
			'created_at' => new \DateTime(),
		]);
	}

	/**
	 * Vrátí auditní záznamy pro dané usnesení
	 */
	public function getElectionAuditLogs(int $electionId): array
	{
		return $this->database->table('election_audit_logs')
			->where('election_id', $electionId)
			->order('created_at ASC, id ASC')
			->fetchAll();
	}

	/**
	 * Zapíše auditní záznam o přihlášení nebo přepnutí role
	 */
	public function logUserLogin(
		int $personId,
		string $userName,
		string $personName,
		?int $unitId,
		?string $unitName,
		?string $roleName,
		?string $ipAddress,
		?string $userAgent,
		string $action = 'login'
	): void {
		$this->database->table('user_login_logs')->insert([
			'person_id' => $personId,
			'user_name' => $userName,
			'person_name' => $personName,
			'unit_id' => $unitId,
			'unit_name' => $unitName,
			'role_name' => $roleName,
			'action' => $action,
			'ip_address' => $ipAddress,
			'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
			'logged_at' => new \DateTime(),
		]);
	}

	/**
	 * Vrátí historii přihlášení uživatelů (jednoduchý výpis)
	 */
	public function getLoginLogs(?int $unitId = null, int $limit = 100): array
	{
		$query = $this->database->table('user_login_logs')
			->order('logged_at DESC, id DESC')
			->limit($limit);

		if ($unitId !== null) {
			$query->where('unit_id', $unitId);
		}

		return $query->fetchAll();
	}

	/**
	 * Vrátí filtrovaný a stránkovaný seznam přihlášení
	 */
	public function getLoginLogsFiltered(?int $unitId = null, ?string $search = null, int $limit = 50, int $offset = 0): array
	{
		$query = $this->database->table('user_login_logs')
			->order('logged_at DESC, id DESC')
			->limit($limit, $offset);

		if ($unitId !== null) {
			$query->where('unit_id', $unitId);
		}

		if ($search !== null && trim($search) !== '') {
			$term = '%' . trim($search) . '%';
			$query->where('person_name LIKE ? OR user_name LIKE ? OR role_name LIKE ? OR action LIKE ? OR ip_address LIKE ?', $term, $term, $term, $term, $term);
		}

		return $query->fetchAll();
	}

	/**
	 * Vrátí celkový počet záznamů pro zadaný filtr auditu přihlášení
	 */
	public function getLoginLogsCount(?int $unitId = null, ?string $search = null): int
	{
		$query = $this->database->table('user_login_logs');

		if ($unitId !== null) {
			$query->where('unit_id', $unitId);
		}

		if ($search !== null && trim($search) !== '') {
			$term = '%' . trim($search) . '%';
			$query->where('person_name LIKE ? OR user_name LIKE ? OR role_name LIKE ? OR action LIKE ? OR ip_address LIKE ?', $term, $term, $term, $term, $term);
		}

		return $query->count('*');
	}

	/**
	 * Vrátí všechna uzavřená a stornovaná usnesení jednotky pro export (včetně výsledků a jmenného rozpadu hlasů)
	 */
	public function getClosedElectionsForExport(int $unitId): array
	{
		$now = new \DateTime();
		$totalMembers = count($this->getCouncilMembers($unitId));

		$elections = $this->database->table('elections')
			->where('unit_id', $unitId)
			->where('status IN (?) OR (status = ? AND end_date <= ?)', ['adopted', 'rejected', 'cancelled'], 'published', $now)
			->order('created_at DESC, id DESC')
			->fetchAll();

		$result = [];
		foreach ($elections as $el) {
			$elId = (int)$el->id;
			$options = $this->getOptions($elId);
			$proCount = 0;
			$againstCount = 0;
			$abstainCount = 0;

			foreach ($options as $opt) {
				if ($opt->title === 'Pro') {
					$proCount = (int)$opt->votes_count;
				} elseif ($opt->title === 'Proti') {
					$againstCount = (int)$opt->votes_count;
				} elseif ($opt->title === 'Zdržel se') {
					$abstainCount = (int)$opt->votes_count;
				}
			}

			$isAdopted = ($el->status === 'adopted') || ($totalMembers > 0 && ($proCount > ($totalMembers / 2)));
			$statusLabel = 'NEPŘIJATO';
			if ($el->status === 'cancelled') {
				$statusLabel = 'Stornováno';
			} elseif ($isAdopted) {
				$statusLabel = 'PŘIJATO';
			}

			// Jmenný seznam odevzdaných hlasů
			$votes = $this->database->table('votes')
				->where('election_id', $elId)
				->order('created_at ASC, id ASC')
				->fetchAll();

			$votesDetails = [];
			foreach ($votes as $v) {
				$optTitle = $v->option ? $v->option->title : '–';
				$vDate = $v->created_at ? $v->created_at->format('d. m. Y H:i') : '';
				$votesDetails[] = "{$v->person_name}: {$optTitle}" . ($vDate ? " ({$vDate})" : '');
			}

			$result[] = [
				'id' => $elId,
				'resolution_number' => $el->resolution_number,
				'title' => $el->title,
				'description' => $el->description,
				'status' => $el->status,
				'statusLabel' => $statusLabel,
				'isAdopted' => $isAdopted,
				'proposal_received_date' => $el->proposal_received_date,
				'end_date' => $el->end_date,
				'cancelled_at' => $el->cancelled_at,
				'cancellation_reason' => $el->cancellation_reason,
				'created_at' => $el->created_at,
				'pro' => $proCount,
				'against' => $againstCount,
				'abstain' => $abstainCount,
				'totalMembers' => $totalMembers,
				'votesDetails' => implode(' | ', $votesDetails),
			];
		}

		return $result;
	}

	/**
	 * Získá výsledky hlasování (agregovaně i jmenovitě)
	 */
	public function getElectionResults(int $electionId): array
	{
		$options = $this->getOptions($electionId);
		$votes = $this->database->table('votes')
			->where('election_id', $electionId)
			->fetchAll();

		$votesByPerson = [];
		foreach ($votes as $vote) {
			$votesByPerson[$vote->person_id] = [
				'person_name' => $vote->person_name,
				'option_id' => $vote->option_id,
				'option_title' => $vote->option->title,
				'created_at' => $vote->created_at,
			];
		}

		return [
			'options' => $options,
			'votes' => $votesByPerson,
		];
	}

	/**
	 * Spočítá a vrátí výsledky (přijato / nepřijato, počty hlasů) pro zadaný seznam usnesení
	 */
	public function getElectionsOutcomes(array $elections, int $unitId): array
	{
		$totalMembers = count($this->getCouncilMembers($unitId));
		$outcomes = [];

		foreach ($elections as $el) {
			$elId = (int)$el->id;
			if ($el->status === 'cancelled') {
				$outcomes[$elId] = [
					'status' => 'cancelled',
					'isAdopted' => false,
					'statusLabel' => '🚫 Stornováno',
					'badgeBg' => '#f1f5f9',
					'badgeColor' => '#475569',
					'borderLeft' => '#94a3b8',
					'pro' => 0,
					'against' => 0,
					'abstain' => 0,
					'totalMembers' => $totalMembers,
				];
				continue;
			}

			// Spočítáme hlasy pro jednotlivé možnosti
			$options = $this->getOptions($elId);
			$proCount = 0;
			$againstCount = 0;
			$abstainCount = 0;

			foreach ($options as $opt) {
				if ($opt->title === 'Pro') {
					$proCount = (int)$opt->votes_count;
				} elseif ($opt->title === 'Proti') {
					$againstCount = (int)$opt->votes_count;
				} elseif ($opt->title === 'Zdržel se') {
					$abstainCount = (int)$opt->votes_count;
				}
			}

			// Nadpoloviční většina všech členů rady (pokud již stav není uložen jako adopted / rejected)
			$isAdopted = ($el->status === 'adopted') || ($totalMembers > 0 && ($proCount > ($totalMembers / 2)));

			$outcomes[$elId] = [
				'status' => $isAdopted ? 'adopted' : 'rejected',
				'isAdopted' => $isAdopted,
				'statusLabel' => $isAdopted ? '✓ PŘIJATO' : '✕ NEPŘIJATO',
				'badgeBg' => $isAdopted ? '#dcfce7' : '#fee2e2',
				'badgeColor' => $isAdopted ? '#166534' : '#991b1b',
				'borderLeft' => $isAdopted ? '#16a34a' : '#dc2626',
				'pro' => $proCount,
				'against' => $againstCount,
				'abstain' => $abstainCount,
				'totalMembers' => $totalMembers,
			];
		}

		return $outcomes;
	}

	/**
	 * Získá kompletní historii a auditní log změny hlasů pro dané hlasování
	 */
	public function getVoteHistory(int $electionId): array
	{
		return $this->database->table('vote_history')
			->where('election_id', $electionId)
			->order('created_at DESC, id DESC')
			->fetchAll();
	}

	/**
	 * Vrátí seznamy hlasování na základě oprávnění přihlášeného uživatele
	 */
	public function getElectionsForUser(int $unitId, int $personId, bool $isAdmin, bool $isCouncilMember): array
	{
		$now = new \DateTime();
		$electionsQuery = $this->database->table('elections')->where('unit_id', $unitId);

		$active = [];
		$drafts = [];
		$closed = [];

		if ($isAdmin) {
			// 1. Admin jednotky vidí všechno pro svou jednotku (drafty, aktivní usnesení i ukončená)
			$all = $electionsQuery->order('created_at DESC')->fetchAll();
			foreach ($all as $el) {
				if ($el->status === 'draft') {
					$drafts[] = $el;
				} elseif (in_array($el->status, ['cancelled', 'adopted', 'rejected'], true)) {
					$closed[] = $el;
				} elseif ($el->end_date > $now) {
					$active[] = $el;
				} else {
					$closed[] = $el;
				}
			}
		} elseif ($isCouncilMember) {
			// 2. Člen rady jednotky vidí probíhající hlasování a ukončená/stornovaná usnesení (nevidí drafty)
			$all = $electionsQuery->where('status', ['published', 'adopted', 'rejected', 'cancelled'])->order('created_at DESC')->fetchAll();
			foreach ($all as $el) {
				if (in_array($el->status, ['cancelled', 'adopted', 'rejected'], true)) {
					$closed[] = $el;
				} elseif ($el->end_date > $now) {
					$active[] = $el;
				} else {
					$closed[] = $el;
				}
			}
		} else {
			// 3. Uživatel, který není admin ani člen rady:
			// Nevidí rozpracované návrhy (drafty) ani probíhající hlasování.
			// Vidí POUZE ta ukončená/stornovaná usnesení, pro která sám v minulosti hlasoval (byl členem rady v době hlasování).
			$participatedElectionIds = $this->database->table('votes')
				->where('person_id', $personId)
				->select('election_id')
				->fetchAll();

			$ids = array_map(fn($row) => (int)$row->election_id, $participatedElectionIds);

			if (!empty($ids)) {
				$past = $this->database->table('elections')
					->where('unit_id', $unitId)
					->where('status', ['published', 'adopted', 'rejected', 'cancelled'])
					->where('id', $ids)
					->order('created_at DESC')
					->fetchAll();

				foreach ($past as $el) {
					if (in_array($el->status, ['cancelled', 'adopted', 'rejected'], true) || $el->end_date <= $now) {
						$closed[] = $el;
					}
				}
			}
		}

		return [
			'active' => $active,
			'drafts' => $drafts,
			'closed' => $closed,
		];
	}

	/**
	 * Získá všechny hlasy daného uživatele indexované podle election_id
	 */
	public function getUserVotesForPerson(int $personId): array
	{
		$votes = $this->database->table('votes')
			->where('person_id', $personId)
			->fetchAll();

		$result = [];
		foreach ($votes as $vote) {
			$result[$vote->election_id] = [
				'option_id' => $vote->option_id,
				'option_title' => $vote->option->title,
				'created_at' => $vote->created_at,
			];
		}
		return $result;
	}

	/**
	 * Získá uživatele podle jeho SkautIS person ID
	 */
	public function getUserByPersonId(int $personId): ?ActiveRow
	{
		return $this->database->table('users')->where('skautis_person_id', $personId)->fetch();
	}

	/**
	 * Získá všechna publikovaná usnesení jednotky, která dosud nemají odeslanou notifikaci
	 */
	public function getUnnotifiedPublishedElections(int $unitId): array
	{
		$now = new \DateTime();
		return $this->database->table('elections')
			->where('unit_id', $unitId)
			->where('status', 'published')
			->where('notification_sent', 0)
			->where('reminder_sent', 0)
			->where('end_date >', $now)
			->order('created_at ASC')
			->fetchAll();
	}

	/**
	 * Označí usnesení jako notifikovaná a zapíše auditní záznamy
	 */
	public function markElectionsNotified(
		array $electionIds,
		array $sentEmails,
		int $personId,
		string $personName,
		?string $roleName = null
	): void {
		if (empty($electionIds)) {
			return;
		}

		$this->database->table('elections')
			->where('id', $electionIds)
			->update(['notification_sent' => 1]);

		$details = "Odeslána hromadná notifikace o vyhlášení hlasování.";
		if (!empty($sentEmails)) {
			$details .= " E-mail odeslán na adresy (" . count($sentEmails) . "): " . implode(', ', $sentEmails);
		}

		foreach ($electionIds as $elId) {
			$this->logElectionAudit(
				(int)$elId,
				$personId,
				$personName,
				$roleName,
				'notification_sent',
				$details
			);
		}
	}

	/**
	 * Označí usnesení jako upomenutá (a pokud dosud nebyla odeslána prvotní výzva, označí i tu jako vyřízenou)
	 */
	public function markElectionsReminderSent(array $electionIds): void
	{
		if (empty($electionIds)) {
			return;
		}
		$this->database->table('elections')
			->where('id', $electionIds)
			->update([
				'reminder_sent' => 1,
				'notification_sent' => 1,
			]);
	}
}
