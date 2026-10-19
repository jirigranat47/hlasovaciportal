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

	public function testIsAllowedRole(): void
	{
		// Povolené role dle Key
		Assert::true(SkautisAuthManager::isAllowedRole(['Key' => 'vedouciKraj']));
		Assert::true(SkautisAuthManager::isAllowedRole(['Key' => 'cinovnikKraj']));
		Assert::true(SkautisAuthManager::isAllowedRole(['Key' => 'vedouciOkres']));
		Assert::true(SkautisAuthManager::isAllowedRole(['Key' => 'cinovnikOkres']));
		Assert::true(SkautisAuthManager::isAllowedRole(['Key' => 'vedouciStredisko']));
		Assert::true(SkautisAuthManager::isAllowedRole(['Key' => 'cinovnikStredisko']));

		// Povolené role dle Role ze SkautISu (i bez Key)
		Assert::true(SkautisAuthManager::isAllowedRole(['Role' => 'Kraj: vedoucí/admin']));
		Assert::true(SkautisAuthManager::isAllowedRole(['Role' => 'Kraj: aktivní činovník']));
		Assert::true(SkautisAuthManager::isAllowedRole(['Role' => 'Okres: vedoucí/admin']));
		Assert::true(SkautisAuthManager::isAllowedRole(['Role' => 'Okres: aktivní činovník']));
		Assert::true(SkautisAuthManager::isAllowedRole(['Role' => 'Středisko: vedoucí/admin']));
		Assert::true(SkautisAuthManager::isAllowedRole(['Role' => 'Středisko: aktivní činovník']));
		Assert::true(SkautisAuthManager::isAllowedRole(['DisplayName' => 'Středisko: vedoucí/admin - 411.01']));

		// Povolené alternativní názvy (např. z devLogin)
		Assert::true(SkautisAuthManager::isAllowedRole(['Role' => 'Vedoucí kraje']));
		Assert::true(SkautisAuthManager::isAllowedRole(['Role' => 'Vedoucí okresu']));
		Assert::true(SkautisAuthManager::isAllowedRole(['Role' => 'Vedoucí střediska']));
		Assert::true(SkautisAuthManager::isAllowedRole(['Role' => 'Aktivní činovník střediska']));

		// Nepovolené role (oddíly, družiny, tábory, sněmy, hospodáři, členové, revizní komise atd.)
		Assert::false(SkautisAuthManager::isAllowedRole(['Key' => 'vedouciOddil', 'Role' => 'Oddíl: vedoucí/admin']));
		Assert::false(SkautisAuthManager::isAllowedRole(['Key' => 'cinovnikOddil', 'Role' => 'Oddíl: aktivní činovník']));
		Assert::false(SkautisAuthManager::isAllowedRole(['Key' => 'vedouciDruzina', 'Role' => 'Družina: vedoucí/admin']));
		Assert::false(SkautisAuthManager::isAllowedRole(['Key' => 'EventCamp', 'Role' => 'Tábor: účastník (1.7.2025)']));
		Assert::false(SkautisAuthManager::isAllowedRole(['Key' => 'EventCongress', 'Role' => 'Sněm: účastník sněmu (20.4.2024)']));
		Assert::false(SkautisAuthManager::isAllowedRole(['Key' => 'hospodarStredisko', 'Role' => 'Středisko: hospodář']));
		Assert::false(SkautisAuthManager::isAllowedRole(['Key' => 'hospodarKraj', 'Role' => 'Kraj: hospodář']));
		Assert::false(SkautisAuthManager::isAllowedRole(['Role' => 'Středisko: člen']));
		Assert::false(SkautisAuthManager::isAllowedRole(['Role' => 'Středisko: revizní komise']));
		Assert::false(SkautisAuthManager::isAllowedRole(['Role' => 'ZvOJ: vedoucí/admin']));
	}

	public function testFilterAllowedRolesAndSorting(): void
	{
		$rawRoles = [
			['ID' => 1, 'Key' => 'EventCamp', 'Role' => 'Tábor: účastník', 'Unit' => 'Tábor'],
			['ID' => 2, 'Key' => 'cinovnikStredisko', 'Role' => 'Středisko: aktivní činovník', 'Unit' => 'Středisko B'],
			['ID' => 3, 'Key' => 'vedouciOddil', 'Role' => 'Oddíl: vedoucí/admin', 'Unit' => 'Oddíl 1'],
			['ID' => 4, 'Key' => 'vedouciKraj', 'Role' => 'Kraj: vedoucí/admin', 'Unit' => 'Středočeský kraj'],
			['ID' => 5, 'Key' => 'hospodarStredisko', 'Role' => 'Středisko: hospodář', 'Unit' => 'Středisko A'],
			['ID' => 6, 'Key' => 'vedouciStredisko', 'Role' => 'Středisko: vedoucí/admin', 'Unit' => 'Středisko A'],
			['ID' => 7, 'Key' => 'cinovnikOkres', 'Role' => 'Okres: aktivní činovník', 'Unit' => 'Okres Benešov'],
		];

		$filtered = SkautisAuthManager::filterAllowedRoles($rawRoles);
		Assert::count(4, $filtered);

		// Uspořádání: Kraj -> Okres -> Středisko (vedoucí před činovníkem)
		Assert::same(4, $filtered[0]['ID']); // Kraj: vedoucí/admin
		Assert::same(7, $filtered[1]['ID']); // Okres: aktivní činovník
		Assert::same(6, $filtered[2]['ID']); // Středisko: vedoucí/admin
		Assert::same(2, $filtered[3]['ID']); // Středisko: aktivní činovník
	}

	public function testEnsureAllowedRoleSwitchesToAllowedUnit(): void
	{
		// Simulace: uživatel je v session přihlášen jako Oddíl: vedoucí (ID 100), ale v účtu má Středisko i Kraj
		$roles = [
			[
				'ID' => 201,
				'ID_Role' => 201,
				'ID_Unit' => 25893,
				'Key' => 'cinovnikKraj',
				'Role' => 'Kraj: aktivní činovník',
				'DisplayName' => 'Kraj: aktivní činovník',
				'Unit' => 'Karlovarský kraj',
			],
			[
				'ID' => 202,
				'ID_Role' => 202,
				'ID_Unit' => 25893,
				'Key' => 'vedouciStredisko',
				'Role' => 'Středisko: vedoucí/admin',
				'DisplayName' => 'Středisko: vedoucí/admin',
				'Unit' => 'Testovací středisko',
			],
		];

		// Přihlásíme uživatele s rolí oddílu (která není v povolených)
		$this->authManager->simulateLogin(
			unitId: 99999,
			personId: 444,
			personName: 'Petr Skaut',
			roleKey: 'vedouciOddil',
			roleName: 'Oddíl: vedoucí/admin',
			unitName: 'Oddíl Bobrů',
			roles: $roles
		);

		// ensureAllowedRole automaticky přepne na preferovanou povolenou roli (vedouciStredisko)
		$this->authManager->ensureAllowedRole();

		$userData = $this->authManager->getUserData();
		Assert::same(202, $userData['roleId']);
		Assert::same('vedouciStredisko', $userData['roleKey']);
		Assert::same('Testovací středisko', $userData['unitName']);
	}

	public function testIsSuperAdmin(): void
	{
		// Odhlášený uživatel není superadmin
		$this->authManager->logout();
		Assert::false($this->authManager->isSuperAdmin());

		// Přihlášený běžný uživatel bez superadmin ID
		$this->authManager->simulateLogin(unitId: 50001, personId: 99999);
		Assert::false($this->authManager->isSuperAdmin());

		// Nastavíme v reflexi nebo testovacím authManageru superadmin ID
		$refProp = new \ReflectionProperty(SkautisAuthManager::class, 'superadmins');
		$refProp->setValue($this->authManager, [777, 888]);

		// Uživatel s Person ID 777 je superadmin
		$this->authManager->simulateLogin(unitId: 50001, personId: 777);
		Assert::true($this->authManager->isSuperAdmin());

		// Uživatel s Person ID 888 je superadmin
		$this->authManager->simulateLogin(unitId: 50001, personId: 888);
		Assert::true($this->authManager->isSuperAdmin());

		// Jiný uživatel není superadmin
		$this->authManager->simulateLogin(unitId: 50001, personId: 123);
		Assert::false($this->authManager->isSuperAdmin());

		// Reset superadmins
		$refProp->setValue($this->authManager, []);
		Assert::false($this->authManager->isSuperAdmin());
	}
}

(new SkautisAuthManagerTest($container))->run();
