<?php

declare(strict_types=1);

namespace App\Presentation\Election;

use App\Presentation\BasePresenter;
use Nette\Application\UI\Form;
use App\Model\SkautisAuthManager;
use App\Model\VotingRepository;

final class ElectionPresenter extends BasePresenter
{
	public function __construct(
		private SkautisAuthManager $skautisAuthManager,
		private VotingRepository $votingRepository,
		private \App\Model\CronManager $cronManager
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

	protected function beforeRender(): void
	{
		parent::beforeRender();
		$this->template->addFilter('sanitizeNote', [self::class, 'sanitizeNote']);
	}

	public static function sanitizeNote(?string $html): string
	{
		if ($html === null || trim($html) === '') {
			return '';
		}

		$allowedTags = '<b><strong><i><em><u><ul><ol><li><a><p><br>';
		$clean = strip_tags($html, $allowedTags);

		$clean = preg_replace_callback('/<a\s+[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/i', function ($matches) {
			$url = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
			$text = $matches[2];
			return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" style="color: var(--skaut-blue); text-decoration: underline;">' . $text . '</a>';
		}, $clean);

		return $clean;
	}

	private function checkElectionAccess(\Nette\Database\Table\ActiveRow $election, int $personId, int $unitId, bool $isAdmin, bool $isCouncilMember): void
	{
		$isUnitAdmin = $isAdmin && (int)$election->unit_id === $unitId;
		$isUnitCouncilMember = $isCouncilMember && (int)$election->unit_id === $unitId;
		$now = new \DateTime();
		$isClosed = ($election->end_date <= $now || $election->status === 'cancelled');

		// 1. Usnesení rozpracovaná (Draft) vidí POUZE administrátor jednotky
		if ($election->status === 'draft') {
			if (!$isUnitAdmin) {
				$this->flashMessage('Nemáte oprávnění k zobrazení tohoto rozpracovaného návrhu.', 'danger');
				$this->redirect('Home:default');
			}
			return;
		}

		// 2. Usnesení k hlasování (probíhající published) vidí POUZE admin jednotky a členové rady
		if (!$isClosed && $election->status === 'published') {
			if (!$isUnitAdmin && !$isUnitCouncilMember) {
				$this->flashMessage('Nemáte oprávnění k zobrazení tohoto probíhajícího hlasování.', 'danger');
				$this->redirect('Home:default');
			}
			return;
		}

		// 3. Usnesení ukončená vidí admin jednotky, členové rady a uživatelé, kteří pro dané hlasování v minulosti hlasovali
		if ($isClosed) {
			$hasVoted = $this->votingRepository->hasVoted((int)$election->id, $personId);
			if (!$isUnitAdmin && !$isUnitCouncilMember && !$hasVoted) {
				$this->flashMessage('Nemáte oprávnění k zobrazení tohoto ukončeného hlasování.', 'danger');
				$this->redirect('Home:default');
			}
			return;
		}

		$this->flashMessage('Nemáte oprávnění k zobrazení tohoto hlasování.', 'danger');
		$this->redirect('Home:default');
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

		// Kontrola přístupových práv dle přesných pravidel
		$this->checkElectionAccess($election, $personId, $unitId, $isAdmin, $isCouncilMember);

		$now = new \DateTime();
		$isClosed = ($election->end_date <= $now || $election->status === 'cancelled');

		$options = $this->votingRepository->getOptions($id);
		$userVote = $this->votingRepository->getUserVote($id, $personId);
		$hasVoted = ($userVote !== null);
		$canVote = $isCouncilMember && !$isClosed && $election->status === 'published';

		$this->template->election = $election;
		$this->template->options = $options;
		$this->template->hasVoted = $hasVoted;
		$this->template->userVote = $userVote;
		$this->template->userVoteOptionId = $userVote ? (int)$userVote->option_id : null;
		$this->template->isClosed = $isClosed;
		$this->template->isCouncilMember = $isCouncilMember;
		$this->template->isAdmin = $isAdmin;
		$this->template->isCreator = $isCreator;
		$this->template->canVote = $canVote;
		$this->template->canRevertToDraft = $isAdmin && $this->votingRepository->canRevertToDraft($id);

		// Administrátoři jednotky a členové rady vidí jmenný seznam hlasů
		$showVoterList = $isAdmin || $isCouncilMember;
		$this->template->showVoterList = $showVoterList;

		if ($showVoterList) {
			$councilMembers = $this->votingRepository->getCouncilMembers($unitId);
			$results = $this->votingRepository->getElectionResults($id);
			$voteHistory = $this->votingRepository->getVoteHistory($id);

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
					'votedAt' => $vote ? $vote['created_at'] : null,
				];
			}

			$totalMembers = count($councilMembers);
			$proCount = $votesCount['Pro'] ?? 0;

			$this->template->voterList = $voterList;
			$this->template->votesCount = $votesCount;
			$this->template->totalMembers = $totalMembers;
			$this->template->isAdopted = $proCount > ($totalMembers / 2);
			$this->template->voteHistory = $voteHistory;
		} else {
			// Pro bývalé členy, kteří v minulosti hlasovali, spočítáme agregované výsledky
			$votesCount = [
				'Pro' => 0,
				'Proti' => 0,
				'Zdržel se' => 0,
			];
			foreach ($options as $opt) {
				$votesCount[$opt->title] = $opt->votes_count;
			}
			$this->template->votesCount = $votesCount;
			
			$councilMembersCount = count($this->votingRepository->getCouncilMembers((int)$election->unit_id));
			$proCount = $votesCount['Pro'] ?? 0;
			$this->template->totalMembers = $councilMembersCount;
			$this->template->isAdopted = $proCount > ($councilMembersCount / 2);
			$this->template->voteHistory = [];
		}

		$this->template->auditLogs = $this->votingRepository->getElectionAuditLogs($id);
	}

	public function actionHistory(int $id): void
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

		// Kontrola přístupových práv
		$this->checkElectionAccess($election, $personId, $unitId, $isAdmin, $isCouncilMember);

		$now = new \DateTime();
		$isClosed = ($election->end_date <= $now || $election->status === 'cancelled');

		$this->template->election = $election;
		$this->template->isClosed = $isClosed;
		$this->template->auditLogs = $this->votingRepository->getElectionAuditLogs($id);
		$this->template->voteHistory = $this->votingRepository->getVoteHistory($id);
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

		$success = $this->votingRepository->vote($electionId, $optionId, $personId, $userData['personName'], $userData['roleName'] ?? null);

		if ($success) {
			$this->flashMessage('Váš hlas byl úspěšně zaznamenán / změněn.', 'success');
		} else {
			$this->flashMessage('Při ukládání hlasu došlo k chybě.', 'danger');
		}

		$this->redirect('show', $electionId);
	}

	public function actionCreate(): void
	{
		$this->checkAdmin();
	}

	public function actionDuplicate(int $id): void
	{
		$this->checkAdmin();
		$election = $this->votingRepository->getElection($id);
		if (!$election) {
			$this->error('Hlasování nebylo nalezeno.', 404);
		}

		$userData = $this->skautisAuthManager->getUserData();
		$unitId = (int)($userData['unitId'] ?? 0);

		$newResNum = $election->resolution_number . '-oprava';
		if ($this->votingRepository->isResolutionNumberExists($unitId, $newResNum)) {
			$newResNum = $election->resolution_number . '-' . time();
		}

		$this['electionForm']->setDefaults([
			'resolution_number' => $newResNum,
			'title' => $election->title,
			'description' => $election->description,
			'proposal_received_date' => $election->proposal_received_date ? $election->proposal_received_date->format('Y-m-d') : null,
			'end_date' => (new \DateTime('+7 days'))->format('Y-m-d'),
		]);

		$this->template->isDuplicate = true;
		$this->template->originalResolutionNumber = $election->resolution_number;
		$this->setView('create');
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
			'resolution_number' => $election->resolution_number,
			'title' => $election->title,
			'description' => $election->description,
			'proposal_received_date' => $election->proposal_received_date ? $election->proposal_received_date->format('Y-m-d') : null,
			'end_date' => $election->end_date->format('Y-m-d'),
		]);
		$this->template->id = $id;
	}

	public function actionPublish(int $id): void
	{
		$this->checkAdmin();
		$election = $this->votingRepository->getElection($id);
		if (!$election) {
			$this->error('Hlasování nebylo nalezeno.', 404);
		}

		$userData = $this->skautisAuthManager->getUserData();
		$personId = (int)$userData['personId'];

		$this->votingRepository->publishElection($id, $personId, $userData['personName'], $userData['roleName'] ?? null);
		$this->flashMessage('Hlasování bylo úspěšně publikováno. Notifikaci členům rady můžete odeslat souhrnně z hlavní stránky (nebo odejde automaticky v nočním souhrnu).', 'success');
		$this->redirect('Home:default');
	}

	public function actionRevertToDraft(int $id): void
	{
		$this->checkAdmin();
		$election = $this->votingRepository->getElection($id);
		if (!$election) {
			$this->error('Hlasování nebylo nalezeno.', 404);
		}

		$userData = $this->skautisAuthManager->getUserData();
		$personId = (int)$userData['personId'];

		if ($this->votingRepository->revertToDraft($id, $personId, $userData['personName'], $userData['roleName'] ?? null)) {
			$this->flashMessage('Usnesení bylo úspěšně vráceno do stavu Návrh (Draft). Nyní jej můžete upravit a znovu publikovat.', 'success');
			$this->redirect('show', $id);
		} else {
			$this->flashMessage('Usnesení nelze vrátit do návrhu, protože již bylo zahájeno hlasování a odevzdán hlas (v takovém případě lze pouze stornovat).', 'danger');
			$this->redirect('show', $id);
		}
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

		$form->addText('resolution_number', 'Číslo usnesení:')
			->setRequired('Zadejte číslo usnesení.')
			->addRule(function ($control) use ($form) {
				$userData = $this->skautisAuthManager->getUserData();
				$unitId = (int)($userData['unitId'] ?? 0);
				$id = $this->getParameter('id');
				$excludeId = $id !== null ? (int)$id : null;
				return !$this->votingRepository->isResolutionNumberExists($unitId, (string)$control->getValue(), $excludeId);
			}, 'Číslo usnesení v rámci této jednotky již existuje. Zadejte prosím jiné číslo.');

		$form->addTextArea('title', 'Text usnesení:')
			->setRequired('Zadejte text usnesení.');

		$form->addTextArea('description', 'Poznámka:')
			->setNullable();

		$form->addText('proposal_received_date', 'Datum obdržení návrhu:')
			->setHtmlType('date')
			->setNullable();

		$todayStr = (new \DateTime())->format('Y-m-d');

		$form->addText('end_date', 'Datum konce hlasování (do 23:59):')
			->setHtmlType('date')
			->setHtmlAttribute('min', $todayStr)
			->setRequired('Zadejte datum konce hlasování.')
			->addRule(function ($control) {
				$val = $control->getValue();
				if (!$val) {
					return true;
				}
				$endDate = new \DateTime($val);
				$endDate->setTime(23, 59, 59);
				return $endDate > new \DateTime();
			}, 'Datum konce hlasování musí být v budoucnosti. Nelze zadat datum v minulosti.');

		$form->addSubmit('submit', 'Uložit hlasování');

		$form->onSuccess[] = function (Form $form, \stdClass $values): void {
			$userData = $this->skautisAuthManager->getUserData();
			$unitId = (int)$userData['unitId'];
			$personId = (int)$userData['personId'];
			$personName = $userData['personName'];
			$roleName = $userData['roleName'] ?? null;

			$isEdit = ($this->getAction() === 'edit' && $this->getParameter('id') !== null);
			$redirectTarget = null;
			try {
				if ($isEdit) {
					$id = (int)$this->getParameter('id');
					$this->votingRepository->updateElection($id, (array)$values, $personId, $personName, $roleName);
					$this->flashMessage('Hlasování bylo úspěšně upraveno.', 'success');
					$redirectTarget = ['show', $id];
				} else {
					$election = $this->votingRepository->createElection((array)$values, $unitId, $personId, $personName, $roleName);
					$this->flashMessage('Návrh hlasování byl úspěšně vytvořen (zatím v režimu Draft).', 'success');
					$redirectTarget = ['show', (int)$election->id];
				}
			} catch (\Throwable $e) {
				$this->flashMessage('Chyba při ukládání: ' . $e->getMessage(), 'danger');
				return;
			}

			if ($redirectTarget) {
				$this->redirect(...$redirectTarget);
			}
		};

		return $form;
	}

	protected function createComponentCancelForm(): Form
	{
		$form = new Form();
		$form->addTextArea('cancellation_reason', 'Důvod stornování hlasování:')
			->setRequired('Zadejte prosím důvod stornování hlasování.');
		$form->addSubmit('submit', 'Potvrdit stornování hlasování');

		$form->onSuccess[] = function (Form $form, \stdClass $values): void {
			$this->checkAdmin();
			$id = (int)$this->getParameter('id');
			$userData = $this->skautisAuthManager->getUserData();
			$personId = (int)$userData['personId'];
			$personName = $userData['personName'];
			$roleName = $userData['roleName'] ?? null;

			$election = $this->votingRepository->getElection($id);
			if (!$election || $election->status !== 'published') {
				$this->flashMessage('Stornovat lze pouze probíhající (publikovaná) hlasování.', 'warning');
				$this->redirect('show', $id);
			}

			// 1. Odešleme e-mailové upozornění a získáme seznam adres příjemců
			$sentEmails = $this->cronManager->sendCancellationNotification($election, $values->cancellation_reason);

			// 2. Provedeme storno v DB a zapíšeme audit včetně seznamu adres
			$this->votingRepository->cancelElection(
				$id,
				$values->cancellation_reason,
				$personId,
				$personName,
				$roleName,
				$sentEmails
			);

			$this->flashMessage('Hlasování bylo úspěšně stornováno a členům rady byla odeslána notifikace.', 'success');
			$this->redirect('show', $id);
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
