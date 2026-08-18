<?php

declare(strict_types=1);

namespace App\Model\Skautis;

use Nette\Http\Session;
use Nette\Http\SessionSection;
use Skautis\SessionAdapter\AdapterInterface;

/**
 * Modern Nette session adapter for SkautIS library (from SkautisNette)
 */
class SessionAdapter implements AdapterInterface
{
	protected SessionSection $sessionSection;

	public function __construct(Session $session)
	{
		$this->sessionSection = $session->getSection('skautis_session_adapter');
	}

	public function set($id, $value): void
	{
		$this->sessionSection->$id = $value;
	}

	public function get($id)
	{
		return $this->sessionSection->$id ?? null;
	}

	public function has($id): bool
	{
		return isset($this->sessionSection->$id);
	}

	public function isStarted(): bool
	{
		return true;
	}
}
