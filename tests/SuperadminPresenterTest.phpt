<?php

declare(strict_types=1);

namespace App\Tests;

use App\Model\SkautisAuthManager;
use App\Model\SuperadminRepository;
use App\Model\VotingRepository;
use App\Presentation\Superadmin\SuperadminPresenter;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Responses\RedirectResponse;
use Tester\Assert;

$container = require __DIR__ . '/bootstrap.php';

class SuperadminPresenterTest extends BaseTestCase
{
	private SkautisAuthManager $authManager;
	private SuperadminRepository $superadminRepository;
	private VotingRepository $votingRepository;
	private IPresenterFactory $presenterFactory;

	public function setUp(): void
	{
		$this->authManager = $this->getService(SkautisAuthManager::class);
		$this->superadminRepository = $this->getService(SuperadminRepository::class);
		$this->votingRepository = $this->getService(VotingRepository::class);
		$this->presenterFactory = $this->getService(IPresenterFactory::class);
		$this->cleanDatabase();
	}

	public function testAccessDeniedForNonSuperadmin(): void
	{
		// 1. Odhlášený uživatel je přesměrován na přihlášení
		$this->authManager->logout();

		/** @var SuperadminPresenter $presenter */
		$presenter = $this->presenterFactory->createPresenter('Superadmin');
		$presenter->autoCanonicalize = false;

		$request = new Request('Superadmin', 'GET', ['action' => 'default']);
		$response = $presenter->run($request);

		Assert::type(RedirectResponse::class, $response);
		/** @var RedirectResponse $response */
		Assert::contains('login', $response->getUrl());

		// 2. Přihlášený běžný správce (není v superadmins) je odmítnut
		$this->authManager->simulateLogin(unitId: 10001, personId: 5555);
		Assert::false($this->authManager->isSuperAdmin());

		$presenter2 = $this->presenterFactory->createPresenter('Superadmin');
		$presenter2->autoCanonicalize = false;

		$response2 = $presenter2->run($request);
		Assert::type(RedirectResponse::class, $response2);
		/** @var RedirectResponse $response2 */
		Assert::same('/', parse_url($response2->getUrl(), PHP_URL_PATH));
	}

	public function testSuperadminDashboardAndUnitDetail(): void
	{
		$superadminPersonId = 99911;
		$refProp = new \ReflectionProperty(SkautisAuthManager::class, 'superadmins');
		$refProp->setValue($this->authManager, [$superadminPersonId]);

		$unitId = 70001;
		$this->authManager->simulateLogin(unitId: $unitId, personId: $superadminPersonId);
		Assert::true($this->authManager->isSuperAdmin());

		// Vložíme testovací data
		$this->votingRepository->addCouncilMember($unitId, 101, 'Člen Rady 1', 'clen1@test.cz');
		$this->votingRepository->addCouncilMember($unitId, 102, 'Člen Rady 2', 'clen2@test.cz');
		
		$election = $this->votingRepository->createElection(
			[
				'resolution_number' => 'USN-2026-01',
				'title' => 'Schválení rozpočtu 2026',
				'description' => 'Podrobnosti k rozpočtu',
				'end_date' => (new \DateTime())->modify('+3 days')->format('Y-m-d H:i:s'),
			],
			$unitId,
			$superadminPersonId
		);
		$this->votingRepository->publishElection((int)$election->id, $superadminPersonId, 'Admin Testovací');

		// 1. Otestujeme statistiky z repository
		$stats = $this->superadminRepository->getGlobalStatistics();
		Assert::true($stats['unitsCount'] >= 1);
		Assert::true($stats['councilMembersCount'] >= 2);
		Assert::true($stats['totalElections'] >= 1);
		Assert::true($stats['activeElections'] >= 1);

		$unitsList = $this->superadminRepository->getUnitsList();
		Assert::true(count($unitsList) >= 1);

		$foundUnit = null;
		foreach ($unitsList as $u) {
			if ($u['unit_id'] === $unitId) {
				$foundUnit = $u;
				break;
			}
		}
		Assert::notSame(null, $foundUnit);
		Assert::same(2, $foundUnit['council_members_count']);
		Assert::same(1, $foundUnit['total_elections']);
		Assert::same(1, $foundUnit['active_elections']);

		// 2. Otestujeme detail jednotky
		$unitDetail = $this->superadminRepository->getUnitDetail($unitId);
		Assert::notSame(null, $unitDetail);
		Assert::count(2, $unitDetail['members']);
		Assert::count(1, $unitDetail['elections']);

		// Reset superadmins
		$refProp->setValue($this->authManager, []);
	}
}

(new SuperadminPresenterTest($container))->run();
