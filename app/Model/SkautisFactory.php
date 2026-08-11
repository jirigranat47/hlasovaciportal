<?php

declare(strict_types=1);

namespace App\Model;

use Skautis\Skautis;

class SkautisFactory
{
	public static function create(string $appId, bool $isTest): Skautis
	{
		return Skautis::getInstance($appId, $isTest);
	}
}
