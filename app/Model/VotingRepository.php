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
	 */
	public function hasVoted(int $electionId, int $personId): bool
	{
		return $this->database->table('votes')
			->where('election_id', $electionId)
			->where('person_id', $personId)
			->count() > 0;
	}

	/**
	 * Odevzdá hlas (jmenovitě s kontrolou duplicity)
	 */
	public function vote(int $electionId, int $optionId, int $personId, string $personName): bool
	{
		if ($this->hasVoted($electionId, $personId)) {
			return false;
		}

		$this->database->beginTransaction();
		try {
			// Vložíme hlas
			$this->database->table('votes')->insert([
				'election_id' => $electionId,
				'person_id' => $personId,
				'person_name' => $personName,
				'option_id' => $optionId,
				'created_at' => new \DateTime(),
			]);

			// Inkrementujeme počítadlo
			$this->database->table('options')
				->where('id', $optionId)
				->update([
					'votes_count' => $this->database->literal('votes_count + 1'),
				]);

			$this->database->commit();
			return true;
		} catch (\Throwable $e) {
			$this->database->rollBack();
			return false;
		}
	}

	/**
	 * Založí nové hlasování a vytvoří standardní možnosti Pro, Proti, Zdržel se
	 */
	public function createElection(array $values, int $unitId, int $createdByPersonId): ActiveRow
	{
		$this->database->beginTransaction();
		try {
			$election = $this->database->table('elections')->insert([
				'title' => $values['title'],
				'description' => $values['description'] ?? null,
				'unit_id' => $unitId,
				'status' => 'draft',
				'proposal_received_date' => $values['proposal_received_date'] ? new \DateTime($values['proposal_received_date']) : null,
				'end_date' => new \DateTime($values['end_date']),
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
			$election->update([
				'title' => $values['title'],
				'description' => $values['description'] ?? null,
				'proposal_received_date' => $values['proposal_received_date'] ? new \DateTime($values['proposal_received_date']) : null,
				'end_date' => new \DateTime($values['end_date']),
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
			];
		}

		return [
			'options' => $options,
			'votes' => $votesByPerson,
		];
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
}
