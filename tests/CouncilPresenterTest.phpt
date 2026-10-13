<?php

declare(strict_types=1);

namespace App\Tests;

use App\Model\SkautisAuthManager;
use App\Presentation\Council\CouncilPresenter;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Responses\JsonResponse;
use Tester\Assert;

$container = require __DIR__ . '/bootstrap.php';

class CouncilPresenterTest extends BaseTestCase
{
	private SkautisAuthManager $authManager;
	private IPresenterFactory $presenterFactory;

	public function setUp(): void
	{
		$this->authManager = $this->getService(SkautisAuthManager::class);
		$this->presenterFactory = $this->getService(IPresenterFactory::class);
		$this->cleanDatabase();
	}

	public function testHandleSearchSkautisMethodSignature(): void
	{
		$refMethod = new \ReflectionMethod(CouncilPresenter::class, 'handleSearchSkautis');
		$params = $refMethod->getParameters();
		Assert::count(1, $params);
		
		$qParam = $params[0];
		Assert::same('q', $qParam->getName());
		Assert::true($qParam->isOptional(), 'Parameter $q must be optional to allow signal link generation without parameters');
		Assert::true($qParam->allowsNull(), 'Parameter $q must allow null');
	}

	public function testSearchSkautisSignalAndLinkGeneration(): void
	{
		// Simulace přihlášení jako vedoucí kraje
		$this->authManager->simulateLogin(
			unitId: 50001,
			personId: 777,
			personName: 'Karel Krajský',
			roleKey: 'vedouciKraj',
			roleName: 'Vedoucí kraje'
		);

		/** @var CouncilPresenter $presenter */
		$presenter = $this->presenterFactory->createPresenter('Council');
		$presenter->autoCanonicalize = false;
		$presenter->setParent(null, 'Council');
		$presenter->changeAction('default');

		// 1. Ověříme, že odkaz na signál bez parametrů neselže na InvalidLinkException ani nevygeneruje '#' či '#error'
		$linkWithoutParams = $presenter->link('searchSkautis!');
		Assert::type('string', $linkWithoutParams);
		Assert::notContains('#error', $linkWithoutParams);
		Assert::notSame('#', $linkWithoutParams);
		Assert::contains('do=searchSkautis', $linkWithoutParams);

		// 2. Ověříme odkaz s explicitním parametrem q => ''
		$linkWithQ = $presenter->link('searchSkautis!', ['q' => '']);
		Assert::type('string', $linkWithQ);
		Assert::notContains('#error', $linkWithQ);
		Assert::notSame('#', $linkWithQ);
		Assert::contains('do=searchSkautis', $linkWithQ);

		// 3. Ověříme vykonání metody handleSearchSkautis (volá sendJson a vyvolá AbortException)
		try {
			$presenter->handleSearchSkautis('bureš');
			Assert::fail('Metoda handleSearchSkautis má zavolat sendJson() a vyvolat AbortException');
		} catch (\Nette\Application\AbortException) {
			// Očekávané chování Nette při sendJson()
			Assert::true(true);
		}
	}
}

(new CouncilPresenterTest($container))->run();
