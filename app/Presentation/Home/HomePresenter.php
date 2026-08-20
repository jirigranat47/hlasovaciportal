<?php

declare(strict_types=1);

namespace App\Presentation\Home;

use App\Presentation\BasePresenter;
use App\Model\SkautisAuthManager;
use App\Model\VotingRepository;
use App\Model\CronManager;

final class HomePresenter extends BasePresenter
{
	public function __construct(
		private SkautisAuthManager $skautisAuthManager,
		private VotingRepository $votingRepository,
		private CronManager $cronManager
	) {
		parent::__construct();
	}

	public function renderDefault(): void
	{
		$isLoggedIn = $this->skautisAuthManager->isLoggedIn();
		$userData = $isLoggedIn ? $this->skautisAuthManager->getUserData() : null;

		$isAdmin = $isLoggedIn ? $this->skautisAuthManager->isAdmin() : false;
		$this->template->isAdmin = $isAdmin;

		$isCouncilMember = false;
		if ($isLoggedIn && $userData) {
			$isCouncilMember = $this->votingRepository->isCouncilMember((int)$userData['unitId'], (int)$userData['personId']);
		}
		$this->template->isCouncilMember = $isCouncilMember;

		$active = [];
		$drafts = [];
		$closed = [];
		$userVotes = [];
		$unnotifiedElections = [];

		if ($isLoggedIn && $userData) {
			$elections = $this->votingRepository->getElectionsForUser(
				(int)$userData['unitId'],
				(int)$userData['personId'],
				$isAdmin,
				$isCouncilMember
			);
			$active = $elections['active'];
			$drafts = $elections['drafts'];
			$closed = $elections['closed'];

			$userVotes = $this->votingRepository->getUserVotesForPerson((int)$userData['personId']);

			if ($isAdmin) {
				$unnotifiedElections = $this->votingRepository->getUnnotifiedPublishedElections((int)$userData['unitId']);
				$smtp = $this->votingRepository->getSmtpSettings((int)$userData['unitId']);
				$this->template->hasSmtpConfigured = ($smtp !== null && !empty($smtp->host) && !empty($smtp->username) && !empty($smtp->password));
			} else {
				$this->template->hasSmtpConfigured = true;
			}

			$this->template->closedOutcomes = $this->votingRepository->getElectionsOutcomes($closed, (int)$userData['unitId']);
		} else {
			$this->template->hasSmtpConfigured = true;
			$this->template->closedOutcomes = [];
		}

		$this->template->activeElections = $active;
		$this->template->draftElections = $drafts;
		$this->template->closedElections = $closed;
		$this->template->userVotes = $userVotes;
		$this->template->unnotifiedElections = $unnotifiedElections;
		$this->template->unnotifiedElectionsCount = count($unnotifiedElections);
		$this->template->allUserRoles = $isLoggedIn ? $this->skautisAuthManager->getAllUserRoles() : [];
		$this->template->debugRoles = $this->skautisAuthManager->isDebugRoles();
	}

	public function handleSendBatchNotification(): void
	{
		$isLoggedIn = $this->skautisAuthManager->isLoggedIn();
		$isAdmin = $isLoggedIn ? $this->skautisAuthManager->isAdmin() : false;
		if (!$isAdmin) {
			$this->flashMessage('Pro tuto akci musíte mít administrátorská práva.', 'danger');
			$this->redirect('this');
		}

		$userData = $this->skautisAuthManager->getUserData();
		$unitId = (int)$userData['unitId'];

		$unnotified = $this->votingRepository->getUnnotifiedPublishedElections($unitId);
		if (empty($unnotified)) {
			$this->flashMessage('Všechna publikovaná usnesení již byla notifikována.', 'info');
			$this->redirect('this');
		}

		$sentEmails = $this->cronManager->sendBatchNewElectionsNotification($unitId, null, $userData['unitName']);
		if (empty($sentEmails)) {
			$this->flashMessage('E-maily se nepodařilo odeslat. Zkontrolujte prosím nastavení SMTP a zda mají členové rady vyplněný e-mail.', 'warning');
			$this->redirect('this');
		}

		$electionIds = array_map(fn($r) => (int)$r->id, $unnotified);
		$this->votingRepository->markElectionsNotified(
			$electionIds,
			$sentEmails,
			(int)$userData['personId'],
			$userData['personName'],
			$userData['roleName']
		);

		$count = count($electionIds);
		$emailsCount = count($sentEmails);
		$this->flashMessage("Souhrnná notifikace k {$count} novým usnesením byla úspěšně odeslána na {$emailsCount} e-mailových adres členů rady.", 'success');
		$this->redirect('this');
	}
}
