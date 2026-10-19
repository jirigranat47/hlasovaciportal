<?php

declare(strict_types=1);

namespace App\Presentation\Superadmin;

use App\Presentation\BasePresenter;
use App\Model\SkautisAuthManager;
use App\Model\SuperadminRepository;

final class SuperadminPresenter extends BasePresenter
{
	public function __construct(
		private SkautisAuthManager $skautisAuthManager,
		private SuperadminRepository $superadminRepository
	) {
		parent::__construct();
	}

	protected function startup(): void
	{
		parent::startup();

		if (!$this->baseAuthManager->isLoggedIn()) {
			$this->flashMessage('Pro přístup do Superadmin portálu se musíte nejdříve přihlásit přes SkautIS.', 'warning');
			$this->redirect('Sign:in', ['backlink' => $this->storeRequest()]);
		}

		if (!$this->baseAuthManager->isSuperAdmin()) {
			$this->flashMessage('Přístup odepřen: Do Superadmin portálu mají přístup pouze autorizovaní správci aplikace.', 'danger');
			$this->redirect('Home:default');
		}
	}

	public function renderDefault(?string $type = 'all', ?string $q = null, ?string $sort = 'activity'): void
	{
		$sort = $sort ?: 'activity';
		$this->template->globalStats = $this->superadminRepository->getGlobalStatistics();
		$this->template->units = $this->superadminRepository->getUnitsList($type, $q, $sort);
		$this->template->analytics = $this->superadminRepository->getTurnoutAndResolutionStats();
		$this->template->monitoring = $this->superadminRepository->getCronAndEmailMonitoring();
		$this->template->selectedType = $type ?: 'all';
		$this->template->searchQuery = $q ?: '';
		$this->template->selectedSort = $sort;
		$this->template->superAdminPersonIds = $this->skautisAuthManager->getSuperAdminPersonIds();
	}

	public function renderUnit(int $id): void
	{
		$detail = $this->superadminRepository->getUnitDetail($id);
		if (!$detail) {
			$this->flashMessage("Jednotka #$id nebyla v databázi nalezena.", 'warning');
			$this->redirect('default');
		}

		$this->template->unit = $detail['info'];
		$this->template->members = $detail['members'];
		$this->template->elections = $detail['elections'];
		$this->template->recentLogins = $detail['recentLogins'];
		$this->template->settings = $detail['settings'];
		$this->template->hasCustomSmtp = $detail['hasCustomSmtp'];
	}
}
