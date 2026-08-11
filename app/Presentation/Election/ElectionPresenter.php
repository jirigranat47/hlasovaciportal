<?php

declare(strict_types=1);

namespace App\Presentation\Election;

use Nette\Application\UI\Presenter;
use Nette\Application\UI\Form;
use App\Model\SkautisAuthManager;
use App\Model\VotingRepository;

final class ElectionPresenter extends Presenter
{
	public function __construct(
		private SkautisAuthManager $skautisAuthManager,
		private VotingRepository $votingRepository
	) {
		parent::__construct();
	}

	protected function startup(): void
	{
		parent::startup();
		if (!$this->skautisAuthManager->isLoggedIn()) {
			$this->flashMessage('Pro přístup k hlasováním se musíte nejprve přihlásit přes SkautIS.', 'warning');
			$this->redirect('Sign:in');
		}
	}

	public function actionShow(int $id): void
	{
		$election = $this->votingRepository->getElection($id);
		if (!$election) {
			$this->error('Hlasování nebylo nalezeno.', 404);
		}

		$userData = $this->skautisAuthManager->getUserData();
		$unitId = (int)$userData['unitId'];
		$personId = (int)$userData['personId'];

		$isAdmin = $this->skautisAuthManager->isAdmin();
		$isCouncilMember = $this->votingRepository->isCouncilMember($unitId, $personId);
		$isCreator = ((int)$election->created_by_person_id === $personId);

		$now = new \DateTime();
		$isClosed = $election->end_date <= $now;

		// Kontrola přístupových práv k tomuto hlasování
		if (!$isAdmin && !$isCouncilMember) {
			// Nečlen rady vidí pouze uzavřená hlasování, kterých se sám zúčastnil
			$hasVoted = $this->votingRepository->hasVoted($id, $personId);
			if (!$isClosed || !$hasVoted) {
				$this->flashMessage('Nemáte oprávnění k zobrazení tohoto hlasování.', 'danger');
				$this->redirect('Home:default');
			}
		}

		$options = $this->votingRepository->getOptions($id);
		$hasVoted = $this->votingRepository->hasVoted($id, $personId);

		$this->template->election = $election;
		$this->template->options = $options;
		$this->template->hasVoted = $hasVoted;
		$this->template->isClosed = $isClosed;
		$this->template->isCouncilMember = $isCouncilMember;
		$this->template->isAdmin = $isAdmin;
		$this->template->isCreator = $isCreator;

		// Pouze zakladatel a členové rady vidí jmenný seznam hlasů
		$showVoterList = $isCreator || $isCouncilMember;
		$this->template->showVoterList = $showVoterList;

		if ($showVoterList) {
			$councilMembers = $this->votingRepository->getCouncilMembers($unitId);
			$results = $this->votingRepository->getElectionResults($id);

			$voterList = [];
			$votesCount = [
				'Pro' => 0,
				'Proti' => 0,
				'Zdržel se' => 0,
			];

			foreach ($results['options'] as $opt) {
				$votesCount[$opt->title] = $opt->votes_count;
			}

			foreach ($councilMembers as $m) {
				$vote = $results['votes'][$m->person_id] ?? null;
				$voterList[] = [
					'name' => $m->full_name,
					'voted' => $vote !== null,
					'choice' => $vote ? $vote['option_title'] : null,
				];
			}

			$totalMembers = count($councilMembers);
			$proCount = $votesCount['Pro'] ?? 0;

			$this->template->voterList = $voterList;
			$this->template->votesCount = $votesCount;
			$this->template->totalMembers = $totalMembers;
			$this->template->isAdopted = $proCount > ($totalMembers / 2);
		} else {
			// Pro hosty spočítáme pouze anonymní agregované výsledky
			$votesCount = [
				'Pro' => 0,
				'Proti' => 0,
				'Zdržel se' => 0,
			];
			foreach ($options as $opt) {
				$votesCount[$opt->title] = $opt->votes_count;
			}
			$this->template->votesCount = $votesCount;
			
			// Pro nečlena počítáme přijetí podle počtu členů rady (který musíme stejně načíst z DB)
			$councilMembersCount = count($this->votingRepository->getCouncilMembers($unitId));
			$proCount = $votesCount['Pro'] ?? 0;
			$this->template->totalMembers = $councilMembersCount;
			$this->template->isAdopted = $proCount > ($councilMembersCount / 2);
		}
	}

	public function handleVote(int $electionId, int $optionId): void
	{
		$election = $this->votingRepository->getElection($electionId);
		if (!$election || $election->status !== 'published') {
			$this->flashMessage('Hlasování nebylo nalezeno nebo není aktivní.', 'danger');
			$this->redirect('Home:default');
		}

		$userData = $this->skautisAuthManager->getUserData();
		$unitId = (int)$userData['unitId'];
		$personId = (int)$userData['personId'];

		$isCouncilMember = $this->votingRepository->isCouncilMember($unitId, $personId);
		if (!$isCouncilMember) {
			$this->flashMessage('Hlasovat mohou pouze registrovaní členové rady.', 'danger');
			$this->redirect('show', $electionId);
		}

		$now = new \DateTime();
		if ($election->end_date <= $now) {
			$this->flashMessage('Termín pro hlasování již vypršel.', 'danger');
			$this->redirect('show', $electionId);
		}

		$success = $this->votingRepository->vote($electionId, $optionId, $personId, $userData['personName']);

		if ($success) {
			$this->flashMessage('Váš hlas byl úspěšně zaznamenán.', 'success');
		} else {
			$this->flashMessage('V tomto hlasování jste již hlasovali nebo došlo k chybě.', 'danger');
		}

		$this->redirect('show', $electionId);
	}

	public function actionCreate(): void
	{
		$this->checkAdmin();
	}

	public function actionEdit(int $id): void
	{
		$this->checkAdmin();
		$election = $this->votingRepository->getElection($id);
		if (!$election) {
			$this->error('Hlasování nebylo nalezeno.', 404);
		}
		if ($election->status !== 'draft') {
			$this->flashMessage('Upravovat lze pouze hlasování v režimu návrhu (Draft).', 'warning');
			$this->redirect('show', $id);
		}

		$this['electionForm']->setDefaults([
			'title' => $election->title,
			'description' => $election->description,
			'proposal_received_date' => $election->proposal_received_date ? $election->proposal_received_date->format('Y-m-d') : null,
			'end_date' => $election->end_date->format('Y-m-d\TH:i'),
		]);
	}

	public function actionPublish(int $id): void
	{
		$this->checkAdmin();
		$election = $this->votingRepository->getElection($id);
		if (!$election) {
			$this->error('Hlasování nebylo nalezeno.', 404);
		}

		$this->votingRepository->publishElection($id);
		$this->flashMessage('Hlasování bylo úspěšně publikováno. Členům rady bude odeslána notifikace na pozadí.', 'success');
		$this->redirect('Home:default');
	}

	public function actionDelete(int $id): void
	{
		$this->checkAdmin();
		$election = $this->votingRepository->getElection($id);
		if (!$election) {
			$this->error('Hlasování nebylo nalezeno.', 404);
		}

		$this->votingRepository->deleteElection($id);
		$this->flashMessage('Návrh hlasování byl smazán.', 'success');
		$this->redirect('Home:default');
	}

	protected function createComponentElectionForm(): Form
	{
		$form = new Form();

		$form->addText('title', 'Název (Téma) hlasování:')
			->setRequired('Zadejte název hlasování.');

		$form->addTextArea('description', 'Popis / podklady k hlasování:')
			->setNullable();

		$form->addText('proposal_received_date', 'Datum obdržení návrhu:')
			->setHtmlType('date')
			->setNullable();

		$form->addText('end_date', 'Termín ukončení hlasování (do kdy):')
			->setHtmlType('datetime-local')
			->setRequired('Zadejte datum a čas konce hlasování.');

		$form->addSubmit('submit', 'Uložit hlasování');

		$form->onSuccess[] = function (Form $form, \stdClass $values): void {
			$userData = $this->skautisAuthManager->getUserData();
			$unitId = (int)$userData['unitId'];
			$personId = (int)$userData['personId'];

			$id = $this->getParameter('id');
			try {
				if ($id !== null) {
					$this->votingRepository->updateElection((int)$id, (array)$values);
					$this->flashMessage('Hlasování bylo úspěšně upraveno.', 'success');
					$this->redirect('show', $id);
				} else {
					$election = $this->votingRepository->createElection((array)$values, $unitId, $personId);
					$this->flashMessage('Návrh hlasování byl úspěšně vytvořen (zatím v režimu Draft).', 'success');
					$this->redirect('show', $election->id);
				}
			} catch (\Throwable $e) {
				$this->flashMessage('Chyba při ukládání: ' . $e->getMessage(), 'danger');
			}
		};

		return $form;
	}

	private function checkAdmin(): void
	{
		if (!$this->skautisAuthManager->isAdmin()) {
			$this->flashMessage('Tato akce vyžaduje administrátorská práva.', 'danger');
			$this->redirect('Home:default');
		}
	}
}
