<?php

declare(strict_types=1);

namespace App\Tests;

use Nette\Database\Explorer;
use Nette\DI\Container;
use Tester\TestCase;

abstract class BaseTestCase extends TestCase
{
	protected Container $container;

	public function __construct(Container $container)
	{
		$this->container = $container;
	}

	protected function getContainer(): Container
	{
		return $this->container;
	}

	protected function getService(string $type)
	{
		return $this->container->getByType($type);
	}

	protected function cleanDatabase(): void
	{
		/** @var Explorer $db */
		$db = $this->getService(Explorer::class);
		$db->query('SET FOREIGN_KEY_CHECKS = 0');
		$tables = [
			'votes',
			'vote_history',
			'options',
			'elections',
			'election_audit_logs',
			'council_members',
			'unit_settings',
			'smtp_settings',
			'user_login_logs',
			'users',
		];
		foreach ($tables as $table) {
			$db->query("TRUNCATE TABLE `$table`");
		}
		$db->query('SET FOREIGN_KEY_CHECKS = 1');
	}
}
