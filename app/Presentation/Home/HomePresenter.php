<?php

declare(strict_types=1);

namespace App\Presentation\Home;

use App\Presentation\BasePresenter;
use App\Model\SkautisAuthManager;
use App\Model\VotingRepository;

final class HomePresenter extends BasePresenter
{
	public function __construct(
		private SkautisAuthManager $skautisAuthManager,
		private VotingRepository $votingRepository
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
		}

		$this->template->activeElections = $active;
		$this->template->draftElections = $drafts;
		$this->template->closedElections = $closed;
		$this->template->userVotes = $userVotes;
	}
}
