<?php

declare(strict_types=1);

namespace App\Tests;

use App\Model\SkautisAuthManager;
use App\Model\VotingRepository;
use Tester\Assert;

$container = require __DIR__ . '/bootstrap.php';

class SkautisAuthManagerTest extends BaseTestCase
{
	private SkautisAuthManager $authManager;
	private VotingRepository $votingRepository;

	public function setUp(): void
	{
		$this->authManager = $this->getService(SkautisAuthManager::class);
		$this->votingRepository = $this->getService(VotingRepository::class);
		$this->cleanDatabase();
	}

	public function testSimulateLoginAndAdmin(): void
	{
		$this->authManager->logout();
		Assert::false($this->authManager->isLoggedIn());
		Assert::false($this->authManager->isAdmin());

		// Přihlášení jako vedoucí kraje
		$this->authManager->simulateLogin(
			unitId: 50001,
			personId: 777,
			personName: 'Karel Krajský',
			roleKey: 'vedouciKraj',
			roleName: 'Vedoucí kraje',
			unitName: 'Krajská rada Středočeského kraje'
		);

		Assert::true($this->authManager->isLoggedIn());
		Assert::true($this->authManager->isAdmin());

		$userData = $this->authManager->getUserData();
		Assert::same(50001, $userData['unitId']);
		Assert::same(777, $userData['personId']);
		Assert::same('Karel Krajský', $userData['personName']);
		Assert::same('vedouciKraj', $userData['roleKey']);
	}

	public function testGetCouncilMembersAlias(): void
	{
		$this->authManager->simulateLogin(unitId: 50002);
		
		// getCouncilMembers je alias pro getUnitMembers
		$res1 = $this->authManager->getCouncilMembers();
		$res2 = $this->authManager->getUnitMembers();
		Assert::same($res1, $res2);
	}

	public function testSearchUnitMembersWithPersonId(): void
	{
		$this->authManager->simulateLogin(unitId: 50003);

		// Hledání s neplatným nebo prázdným dotazem
		Assert::same([], $this->authManager->searchUnitMembers(''));
		Assert::same([], $this->authManager->searchUnitMembers('   '));

		// Hledání odhlášeného uživatele
		$this->authManager->logout();
		Assert::same([], $this->authManager->searchUnitMembers('Novák'));
	}

	public function testAddCouncilMemberAndRetrieveAll(): void
	{
		$unitId = 60001;
		
		// Ověříme, že v databázi getCouncilMembers vrací všechny přidané členy bez omezení
		$members = $this->votingRepository->getCouncilMembers($unitId);
		Assert::count(0, $members);

		// Přidáme 5 členů
		for ($i = 1; $i <= 5; $i++) {
			$this->votingRepository->addCouncilMember($unitId, 1000 + $i, "Člen Kraje #$i", "clen$i@kraj.cz");
		}

		$allMembers = $this->votingRepository->getCouncilMembers($unitId);
		Assert::count(5, $allMembers);
		Assert::same("Člen Kraje #1", $allMembers[0]->full_name);
		Assert::same("Člen Kraje #5", $allMembers[4]->full_name);
	}
}

(new SkautisAuthManagerTest($container))->run();
