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

	/**
	 * Získá SMTP nastavení pro danou jednotku
	 */
	public function getSmtpSettings(int $unitId): ?ActiveRow
	{
		return $this->database->table('smtp_settings')->get($unitId);
	}

	/**
	 * Uloží SMTP nastavení pro jednotku
	 */
	public function saveSmtpSettings(int $unitId, array $values): void
	{
		$data = [
			'host' => $values['host'],
			'port' => (int)$values['port'],
			'username' => $values['username'],
			'password' => $values['password'],
			'secure' => $values['secure'],
			'from_email' => $values['from_email'],
			'from_name' => $values['from_name'],
		];

		$row = $this->database->table('smtp_settings')->get($unitId);
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
	 * Odevzdá nebo změní hlas (s evidencí historie)
	 */
	public function vote(int $electionId, int $optionId, int $personId, string $personName): bool
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
	public function createElection(array $values, int $unitId, int $createdByPersonId): ActiveRow
	{
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
	public function updateElection(int $id, array $values): void
	{
		$election = $this->getElection($id);
		if ($election && $election->status === 'draft') {
			$endDate = new \DateTime($values['end_date']);
			$endDate->setTime(23, 59, 59);

			$election->update([
				'resolution_number' => trim($values['resolution_number']),
				'title' => $values['title'],
				'description' => $values['description'] ?? null,
				'proposal_received_date' => $values['proposal_received_date'] ? new \DateTime($values['proposal_received_date']) : null,
				'end_date' => $endDate,
			]);
		}
	}

	/**
	 * Přepne hlasování do stavu Published
	 */
	public function publishElection(int $id): void
	{
		$election = $this->getElection($id);
		if ($election && $election->status === 'draft') {
			$election->update([
				'status' => 'published',
			]);
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
				} elseif ($el->end_date > $now) {
					$active[] = $el;
				} else {
					$closed[] = $el;
				}
			}
		} elseif ($isCouncilMember) {
			// Člen rady vidí pouze publikovaná (aktivní a uzavřená)
			$all = $electionsQuery->where('status', 'published')->order('created_at DESC')->fetchAll();
			foreach ($all as $el) {
				if ($el->end_date > $now) {
					$active[] = $el;
				} else {
					$closed[] = $el;
				}
			}
		} else {
			// Nečlen rady nevidí aktivní. Vidí pouze uzavřená, kterých se sám zúčastnil
			$participatedElectionIds = $this->database->table('votes')
				->where('person_id', $personId)
				->select('election_id')
				->fetchAll();

			$ids = array_map(fn($row) => (int)$row->election_id, $participatedElectionIds);

			if (!empty($ids)) {
				$closed = $this->database->table('elections')
					->where('unit_id', $unitId)
					->where('status', 'published')
					->where('id', $ids)
					->where('end_date <=', $now)
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
	 * Získá uživatele podle jeho SkautIS person ID
	 */
	public function getUserByPersonId(int $personId): ?ActiveRow
	{
		return $this->database->table('users')->where('skautis_person_id', $personId)->fetch();
	}
}
