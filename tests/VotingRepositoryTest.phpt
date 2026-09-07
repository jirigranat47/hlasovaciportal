<?php

declare(strict_types=1);

namespace App\Tests;

use App\Model\VotingRepository;
use Tester\Assert;

$container = require __DIR__ . '/bootstrap.php';

class VotingRepositoryTest extends BaseTestCase
{
	private VotingRepository $repository;

	public function setUp(): void
	{
		$this->repository = $this->getService(VotingRepository::class);
		$this->cleanDatabase();
	}

	public function testGetDefaultMinVotingDuration(): void
	{
		$duration = $this->repository->getDefaultMinVotingDuration();
		Assert::type('int', $duration);
		Assert::true($duration >= 1);
	}

	public function testUnitMinVotingDuration(): void
	{
		$unitId = 99901;
		// Pro jednotku bez nastavení vrací výchozí hodnotu
		Assert::same($this->repository->getDefaultMinVotingDuration(), $this->repository->getUnitMinVotingDuration($unitId));

		// Uložíme specifické nastavení
		$this->repository->saveUnitSettings($unitId, [
			'min_voting_duration_hours' => 12,
		]);

		Assert::same(12, $this->repository->getUnitMinVotingDuration($unitId));
	}

	public function testCouncilMembers(): void
	{
		$unitId = 99902;
		$personId = 88801;

		Assert::false($this->repository->isCouncilMember($unitId, $personId));

		$this->repository->addCouncilMember($unitId, $personId, 'Bratr Sova', 'sova@skaut.cz');
		Assert::true($this->repository->isCouncilMember($unitId, $personId));

		$members = array_values($this->repository->getCouncilMembers($unitId));
		Assert::count(1, $members);
		Assert::same('Bratr Sova', $members[0]->full_name);

		$this->repository->removeCouncilMember($unitId, $personId);
		Assert::false($this->repository->isCouncilMember($unitId, $personId));
	}

	public function testPasswordEncryption(): void
	{
		$plain = 'MojeTajneHeslo123!';
		$encrypted = $this->repository->encryptPassword($plain);
		Assert::notSame($plain, $encrypted);
		Assert::same($plain, $this->repository->decryptPassword($encrypted));
	}

	public function testElectionCreationAndVoting(): void
	{
		$unitId = 99903;
		$personId = 88802;

		// 1. Vytvoření hlasování
		$futureDate = (new \DateTime())->modify('+72 hours')->format('Y-m-d H:i:s');
		$election = $this->repository->createElection(
			values: [
				'resolution_number' => '1/2027',
				'title' => 'Schválení rozpočtu 2027',
				'description' => 'Návrh vyrovnaného rozpočtu.',
				'end_date' => $futureDate,
			],
			unitId: $unitId,
			createdByPersonId: $personId,
			personName: 'Admin Test'
		);

		$electionId = (int)$election->id;
		Assert::same('draft', $election->status);
		Assert::same('1/2027', $election->resolution_number);

		// Ověříme možnosti
		$options = array_values($this->repository->getOptions($electionId));
		Assert::count(3, $options);

		// 2. Publikace hlasování
		$this->repository->publishElection($electionId, $personId, 'Admin Test');
		$election = $this->repository->getElection($electionId);
		Assert::same('published', $election->status);

		// 3. Hlasování
		Assert::false($this->repository->hasVoted($electionId, $personId));

		$proOption = $options[0];
		$voted = $this->repository->vote(
			electionId: $electionId,
			optionId: $proOption->id,
			personId: $personId,
			personName: 'Admin Test',
			roleName: 'Člen rady'
		);
		Assert::true($voted);

		Assert::true($this->repository->hasVoted($electionId, $personId));
		$userVote = $this->repository->getUserVote($electionId, $personId);
		Assert::notNull($userVote);
		Assert::same($proOption->id, $userVote->option_id);
	}

	public function testUnnotifiedPublishedElectionsAndReminder(): void
	{
		$unitId = 99904;
		$personId = 88803;

		// 1. Vytvoření aktivního usnesení v budoucnu
		$futureDate = (new \DateTime())->modify('+48 hours')->format('Y-m-d H:i:s');
		$el = $this->repository->createElection(
			values: [
				'resolution_number' => '2/2027',
				'title' => 'Test notifikací',
				'end_date' => $futureDate,
			],
			unitId: $unitId,
			createdByPersonId: $personId,
			personName: 'Admin Notif'
		);
		$elId = (int)$el->id;
		$this->repository->publishElection($elId, $personId, 'Admin Notif');

		// Po publikaci musí být v seznamu neodeslaných
		$unnotified = $this->repository->getUnnotifiedPublishedElections($unitId);
		Assert::count(1, $unnotified);
		Assert::same($elId, (int)$unnotified[0]->id);

		// 2. Po odeslání upomínky se nastaví reminder_sent i notification_sent a tlačítko/výběr zmizí
		$this->repository->markElectionsReminderSent([$elId]);
		$reloaded = $this->repository->getElection($elId);
		Assert::same(1, (int)$reloaded->reminder_sent);
		Assert::same(1, (int)$reloaded->notification_sent);

		$unnotifiedAfterReminder = $this->repository->getUnnotifiedPublishedElections($unitId);
		Assert::count(0, $unnotifiedAfterReminder);

		// 3. Usnesení s vypršeným termínem se také nenabízí
		$pastDate = (new \DateTime())->modify('-2 hours')->format('Y-m-d H:i:s');
		$elPast = $this->repository->createElection(
			values: [
				'resolution_number' => '3/2027',
				'title' => 'Prošlé usnesení',
				'end_date' => $pastDate,
			],
			unitId: $unitId,
			createdByPersonId: $personId,
			personName: 'Admin Notif'
		);
		$elPastId = (int)$elPast->id;
		$this->repository->publishElection($elPastId, $personId, 'Admin Notif');

		$unnotifiedPast = $this->repository->getUnnotifiedPublishedElections($unitId);
		Assert::count(0, $unnotifiedPast);
	}
}

(new VotingRepositoryTest($container))->run();
