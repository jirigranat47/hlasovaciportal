<?php

declare(strict_types=1);

namespace App\Model;

use App\Model\Skautis\SessionAdapter;
use Nette\Http\Session;
use Skautis\Skautis;

class SkautisFactory
{
	public static function create(string $appId, bool $isTest, Session $session): Skautis
	{
		$sessionAdapter = new SessionAdapter($session);
		return Skautis::getInstance($appId, $isTest, true, true, $sessionAdapter);
	}
}
