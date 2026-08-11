<?php

declare(strict_types=1);

namespace App\Presentation\Home;

use Nette\Application\UI\Presenter;
use App\Model\SkautisAuthManager;
use App\Model\VotingRepository;

final class HomePresenter extends Presenter
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
		$this->template->isLoggedIn = $isLoggedIn;
		$this->template->userData = $isLoggedIn ? $this->skautisAuthManager->getUserData() : null;

		$elections = $this->votingRepository->getActiveElections();
		$electionsData = [];

		foreach ($elections as $election) {
			$options = $this->votingRepository->getOptions($election->id);
			$voted = false;

			if ($isLoggedIn) {
				$voterHash = $this->skautisAuthManager->getVoterHash($election->id);
				$voted = $this->votingRepository->hasVoted($election->id, $voterHash);
			}

			$electionsData[] = [
				'entity' => $election,
				'options' => $options,
				'hasVoted' => $voted,
			];
		}

		$this->template->elections = $electionsData;
	}

	public function handleVote(int $electionId, int $optionId): void
	{
		if (!$this->skautisAuthManager->isLoggedIn()) {
			$this->flashMessage('Pro hlasování se musíte nejprve přihlásit přes SkautIS.', 'warning');
			$this->redirect('Sign:in');
		}

		$voterHash = $this->skautisAuthManager->getVoterHash($electionId);
		$success = $this->votingRepository->vote($electionId, $optionId, $voterHash);

		if ($success) {
			$this->flashMessage('Váš hlas byl úspěšně a anonymně zaznamenán! Děkujeme.', 'success');
		} else {
			$this->flashMessage('V tomto hlasování jste již hlasovali nebo došlo k chybě.', 'danger');
		}

		$this->redirect('this');
	}
}
