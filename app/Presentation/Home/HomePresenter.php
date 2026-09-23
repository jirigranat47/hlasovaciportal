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
			}
			$this->template->hasSmtpConfigured = true;

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

	public function actionExport(): void
	{
		if (!$this->skautisAuthManager->isLoggedIn()) {
			$this->flashMessage('Pro stažení exportu se musíte přihlásit.', 'warning');
			$this->redirect('default');
		}

		$userData = $this->skautisAuthManager->getUserData();
		$unitId = (int)$userData['unitId'];
		$personId = (int)$userData['personId'];

		$isAdmin = $this->skautisAuthManager->isAdmin();
		$isCouncilMember = $this->votingRepository->isCouncilMember($unitId, $personId);

		if (!$isAdmin && !$isCouncilMember) {
			$this->flashMessage('Nemáte oprávnění k exportu usnesení této jednotky. Export mohou stahovat pouze administrátoři a členové rady.', 'danger');
			$this->redirect('default');
		}

		$items = $this->votingRepository->getClosedElectionsForExport($unitId);
		$unitName = $userData['unitName'] ?: "jednotka-{$unitId}";
		
		// Bezpečná transliterace bez nutnosti PHP intl rozšíření
		$sanitizedUnit = strtolower(str_replace(
			['á', 'č', 'ď', 'é', 'ě', 'í', 'ň', 'ó', 'ř', 'š', 'ť', 'ú', 'ů', 'ý', 'ž', 'Á', 'Č', 'Ď', 'É', 'Ě', 'Í', 'Ň', 'Ó', 'Ř', 'Š', 'Ť', 'Ú', 'Ů', 'Ý', 'Ž', ' '],
			['a', 'c', 'd', 'e', 'e', 'i', 'n', 'o', 'r', 's', 't', 'u', 'u', 'y', 'z', 'a', 'c', 'd', 'e', 'e', 'i', 'n', 'o', 'r', 's', 't', 'u', 'u', 'y', 'z', '-'],
			$unitName
		));
		$sanitizedUnit = preg_replace('/[^a-z0-9_-]+/', '-', $sanitizedUnit);
		$sanitizedUnit = trim((string)$sanitizedUnit, '-');
		if (empty($sanitizedUnit)) {
			$sanitizedUnit = "jednotka-{$unitId}";
		}

		$filename = "export-usneseni-{$sanitizedUnit}-" . date('Ymd-Hi') . ".csv";

		$response = new \Nette\Application\Responses\CallbackResponse(function ($httpRequest, $httpResponse) use ($items, $filename) {
			$httpResponse->setHeader('Content-Type', 'text/csv; charset=utf-8');
			$httpResponse->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
			$httpResponse->setHeader('Pragma', 'public');
			$httpResponse->setHeader('Expires', '0');
			$httpResponse->setHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');

			$output = fopen('php://output', 'w');
			// UTF-8 BOM pro správné otevření v MS Excel bez rozbité diakritiky
			fwrite($output, "\xEF\xBB\xBF");

			// Hlavička CSV (v PHP 8.4 specifikujeme explicitně separator, enclosure i escape)
			fputcsv($output, [
				'Číslo usnesení',
				'Znění usnesení',
				'Výsledek hlasování',
				'Doplňující informace / Poznámka',
				'Výsledek / Stav',
				'Datum doručení návrhu',
				'Datum ukončení / stornování',
				'Hlasy PRO',
				'Hlasy PROTI',
				'Hlasy ZDRŽEL SE',
				'Celkem členů rady',
				'Jmenovitý rozpad hlasů',
				'Důvod případného storna',
			], ';', '"', '\\');

			foreach ($items as $item) {
				// Vyčistíme HTML značky a normalizujeme mezery
				$cleanDescription = trim(preg_replace('/\s+/', ' ', strip_tags($item['description'] ?? '')));

				$endDateFormatted = '';
				if ($item['status'] === 'cancelled' && $item['cancelled_at']) {
					$endDateFormatted = $item['cancelled_at']->format('d. m. Y H:i');
				} elseif ($item['end_date']) {
					$endDateFormatted = $item['end_date']->format('d. m. Y');
				}

				$votesSummary = "Pro: {$item['pro']}, Proti: {$item['against']}, Zdržel: {$item['abstain']}";

				fputcsv($output, [
					$item['resolution_number'],
					$item['title'],
					$votesSummary,
					$cleanDescription,
					$item['statusLabel'],
					$item['proposal_received_date'] ? $item['proposal_received_date']->format('d. m. Y') : '',
					$endDateFormatted,
					$item['pro'],
					$item['against'],
					$item['abstain'],
					$item['totalMembers'],
					$item['votesDetails'],
					$item['cancellation_reason'] ?? '',
				], ';', '"', '\\');
			}

			fclose($output);
		});

		$this->sendResponse($response);
	}
}
