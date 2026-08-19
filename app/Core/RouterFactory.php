<?php

declare(strict_types=1);

namespace App\Core;

use Nette\Application\Routers\RouteList;

final class RouterFactory
{
	public static function createRouter(): RouteList
	{
		$router = new RouteList();
		$router->addRoute('napoveda', 'Help:default');
		$router->addRoute('help', 'Help:default');
		$router->addRoute('login', 'Sign:in');
		$router->addRoute('login-callback', 'Sign:callback');
		$router->addRoute('logout', 'Sign:out');
		$router->addRoute('<presenter>/<action>[/<id>]', 'Home:default');
		return $router;
	}
}
