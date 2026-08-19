<?php

declare(strict_types=1);

namespace App\Model;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;

class VotingRepository
{
	public function __construct(
		private Explorer $database
	) {}

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

	private string $encryptionKey = 'skaut_hlasovaci_portal_smtp_secret_key_v1';

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
			// Pro zpětnou kompatibilitu s plain textem
			return $encoded;
		}
		$raw = substr($encoded, 4);
		$decoded = base64_decode($raw, true);
		if ($decoded === false || !str_contains($decoded, '::')) {
			return $encoded;
		}
		[$iv, $encrypted] = explode('::', $decoded, 2);
		$key = hash('sha256', $this->encryptionKey, true);
		$decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
		return $decrypted !== false ? $decrypted : '';
	}

	/**
	 * Získá SMTP nastavení pro danou jednotku
	 */
	public function getSmtpSettings(int $unitId): ?ActiveRow
	{
		return $this->database->table('smtp_settings')->get($unitId);
	}

	/**
	 * Uloží SMTP nastavení pro jednotku (s šifrováním hesla)
	 */
	public function saveSmtpSettings(int $unitId, array $values): void
	{
		$row = $this->database->table('smtp_settings')->get($unitId);
		$password = trim((string)($values['password'] ?? ''));

		if (empty($password) && $row) {
			// Ponecháme původní uložené heslo
			$encryptedPassword = $row->password;
		} else {
			$encryptedPassword = $this->encryptPassword($password);
		}

		$data = [
			'host' => $values['host'],
			'port' => (int)$values['port'],
			'username' => $values['username'],
			'password' => $encryptedPassword,
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
			$endDate->setTime(23, 59, 59);

			$election = $this->database->table('elections')->insert([
				'resolution_number' => trim($values['resolution_number']),
				'title' => $values['title'],
				'description' => $values['description'] ?? null,
				'unit_id' => $unitId,
				'status' => 'draft',
				'proposal_received_date' => $values['proposal_received_date'] ? new \DateTime($values['proposal_received_date']) : null,
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
			$this->logElectionAudit(
				(int)$election->id,
				$createdByPersonId,
				$personName ?? "Osoba #$createdByPersonId",
				$roleName,
				'created_draft',
				"Vytvořen návrh usnesení č. {$values['resolution_number']} (Draft). Termín hlasování nastaven do {$endDate->format('d. m. Y H:i')}."
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
			$endDate->setTime(23, 59, 59);

			$changes = [];
			if ($election->resolution_number !== trim($values['resolution_number'])) {
				$changes[] = "číslo usnesení z '{$election->resolution_number}' na '" . trim($values['resolution_number']) . "'";
			}
			if ($election->title !== $values['title']) {
				$changes[] = "text usnesení";
			}
			if ($election->end_date->format('Y-m-d') !== $endDate->format('Y-m-d')) {
				$changes[] = "termín konce na " . $endDate->format('d. m. Y');
			}

			$election->update([
				'resolution_number' => trim($values['resolution_number']),
				'title' => $values['title'],
				'description' => $values['description'] ?? null,
				'proposal_received_date' => $values['proposal_received_date'] ? new \DateTime($values['proposal_received_date']) : null,
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
	 * Vrátí historii přihlášení uživatelů
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
			// Admin vidí všechno pro svou jednotku
			$all = $electionsQuery->order('created_at DESC')->fetchAll();
			foreach ($all as $el) {
				if ($el->status === 'draft') {
					$drafts[] = $el;
				} elseif ($el->status === 'cancelled') {
					$closed[] = $el;
				} elseif ($el->end_date > $now) {
					$active[] = $el;
				} else {
					$closed[] = $el;
				}
			}
		} elseif ($isCouncilMember) {
			// Člen rady vidí publikovaná a stornovaná
			$all = $electionsQuery->where('status', ['published', 'cancelled'])->order('created_at DESC')->fetchAll();
			foreach ($all as $el) {
				if ($el->status === 'cancelled') {
					$closed[] = $el;
				} elseif ($el->end_date > $now) {
					$active[] = $el;
				} else {
					$closed[] = $el;
				}
			}
		} else {
			// Nečlen rady nevidí aktivní. Vidí pouze uzavřená/stornovaná, kterých se sám zúčastnil
			$participatedElectionIds = $this->database->table('votes')
				->where('person_id', $personId)
				->select('election_id')
				->fetchAll();

			$ids = array_map(fn($row) => (int)$row->election_id, $participatedElectionIds);

			if (!empty($ids)) {
				$closed = $this->database->table('elections')
					->where('unit_id', $unitId)
					->where('status', ['published', 'cancelled'])
					->where('id', $ids)
					->order('created_at DESC')
					->fetchAll();
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
}
