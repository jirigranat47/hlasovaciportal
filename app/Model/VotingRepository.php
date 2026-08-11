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
	 * Vrátí všechna aktivní hlasování
	 */
	public function getActiveElections(): array
	{
		return $this->database->table('elections')
			->where('is_active', 1)
			->order('created_at DESC')
			->fetchAll();
	}

	/**
	 * Vrátí konkrétní hlasování s možnostmi
	 */
	public function getElection(int $id): ?ActiveRow
	{
		return $this->database->table('elections')->get($id);
	}

	/**
	 * Vrátí moźnosti pro hlasování
	 */
	public function getOptions(int $electionId): array
	{
		return $this->database->table('options')
			->where('election_id', $electionId)
			->fetchAll();
	}

	/**
	 * Ověří, zda již uživatel v daném hlasování hlasoval
	 */
	public function hasVoted(int $electionId, string $voterHash): bool
	{
		return $this->database->table('votes')
			->where('election_id', $electionId)
			->where('voter_hash', $voterHash)
			->count() > 0;
	}

	/**
	 * Odevzdá hlas v hlasování (anonymně s kontrolou duplicity)
	 */
	public function vote(int $electionId, int $optionId, string $voterHash): bool
	{
		if ($this->hasVoted($electionId, $voterHash)) {
			return false;
		}

		$this->database->beginTransaction();
		try {
			// Uložíme hlas s anonymním hashem
			$this->database->table('votes')->insert([
				'election_id' => $electionId,
				'voter_hash' => $voterHash,
				'option_id' => $optionId,
				'created_at' => new \DateTime(),
			]);

			// Inkrementujeme počítadlo možností
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
}
