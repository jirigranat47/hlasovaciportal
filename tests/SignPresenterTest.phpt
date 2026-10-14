<?php

declare(strict_types=1);

namespace App\Tests;

use App\Model\SkautisAuthManager;
use App\Presentation\Sign\SignPresenter;
use Nette\Application\IPresenterFactory;
use Nette\Application\Responses\RedirectResponse;
use Tester\Assert;

$container = require __DIR__ . '/bootstrap.php';

class SignPresenterTest extends BaseTestCase
{
	private SkautisAuthManager $authManager;
	private IPresenterFactory $presenterFactory;

	public function setUp(): void
	{
		$this->authManager = $this->getService(SkautisAuthManager::class);
		$this->presenterFactory = $this->getService(IPresenterFactory::class);
		$this->cleanDatabase();
	}

	public function testActionInDoesNotContainReturnUrl(): void
	{
		/** @var SignPresenter $presenter */
		$presenter = $this->presenterFactory->createPresenter('Sign');
		$presenter->autoCanonicalize = false;

		$request = new \Nette\Application\Request('Sign', 'GET', ['action' => 'in', 'backlink' => 'xyz789']);
		$response = $presenter->run($request);

		Assert::type(RedirectResponse::class, $response);
		/** @var RedirectResponse $response */
		$url = $response->getUrl();

		// URL nesmí obsahovat ReturnUrl, aby nehavarovala stránka TwoFactor.aspx ve SkautISu
		Assert::type('string', $url);
		Assert::contains('appid=', $url);
		Assert::notContains('ReturnUrl=', $url);

		// Přímé volání getLoginUrl() bez parametrů rovněž nesmí obsahovat ReturnUrl
		$loginUrl = $this->authManager->getLoginUrl();
		Assert::type('string', $loginUrl);
		Assert::contains('appid=', $loginUrl);
		Assert::notContains('ReturnUrl=', $loginUrl);
	}
}

(new SignPresenterTest($container))->run();
