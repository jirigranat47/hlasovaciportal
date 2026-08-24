<?php

declare(strict_types=1);

namespace App\Presentation\Council;

use App\Presentation\BasePresenter;
use Nette\Application\UI\Form;
use App\Model\SkautisAuthManager;
use App\Model\VotingRepository;

final class CouncilPresenter extends BasePresenter
{
	private array $skautisMembers = [];

	public function __construct(
		private SkautisAuthManager $skautisAuthManager,
		private VotingRepository $votingRepository,
		private \App\Model\MailSender $mailSender
	) {
		parent::__construct();
	}

	protected function startup(): void
	{
		parent::startup();
		if (!$this->skautisAuthManager->isLoggedIn()) {
			$this->flashMessage('Pro přístup do správy se musíte přihlásit.', 'warning');
			$this->redirect('Home:default');
		}
		if (!$this->skautisAuthManager->isAdmin()) {
			$this->flashMessage('Nemáte oprávnění ke správě této sekce.', 'danger');
			$this->redirect('Home:default');
		}
	}

	public function actionDefault(): void
	{
		$userData = $this->skautisAuthManager->getUserData();
		$unitId = (int)$userData['unitId'];

		$this->template->userData = $userData;
		$this->template->members = $this->votingRepository->getCouncilMembers($unitId);
		
		// Načteme členy jednotky ze Skautisu pro autocomplete dropdown
		$this->skautisMembers = $this->skautisAuthManager->getUnitMembers();
		$this->template->skautisMembers = $this->skautisMembers;
		$this->template->skautisMembersCount = count($this->skautisMembers);
		$this->template->skautisError = $this->skautisAuthManager->getLastError();
	}

	public function actionDelete(int $personId): void
	{
		$userData = $this->skautisAuthManager->getUserData();
		$unitId = (int)$userData['unitId'];

		$this->votingRepository->removeCouncilMember($unitId, $personId);
		$this->flashMessage('Člen byl odebrán z rady.', 'success');
		$this->redirect('default');
	}

	public function actionSmtp(): void
	{
		$userData = $this->skautisAuthManager->getUserData();
		$this->template->userData = $userData;

		$smtp = $this->votingRepository->getSmtpSettings((int)$userData['unitId']);
		if ($smtp) {
			$data = $smtp->toArray();
			$data['password'] = '';
			$this['smtpForm']->setDefaults($data);
			$this->template->hasExistingPassword = !empty($smtp->password);
		} else {
			$this['smtpForm']->setDefaults([
				'host' => 'smtp.gmail.com',
				'port' => 587,
				'secure' => 'tls',
				'from_name' => 'Rada ' . ($userData['unitName'] ?: 'jednotky'),
			]);
			$this->template->hasExistingPassword = false;
		}
	}

	public function actionSettings(): void
	{
		$userData = $this->skautisAuthManager->getUserData();
		$unitId = (int)$userData['unitId'];
		$this->template->userData = $userData;

		$settings = $this->votingRepository->getUnitSettings($unitId);
		if ($settings) {
			$this['unitSettingsForm']->setDefaults([
				'allow_custom_end_time' => (bool)$settings->allow_custom_end_time,
			]);
		}
	}

	public function actionAudit(int $page = 1, int $limit = 50, ?string $q = null): void
	{
		$userData = $this->skautisAuthManager->getUserData();
		$unitId = (int)$userData['unitId'];
		$this->template->userData = $userData;

		$allowedLimits = [50, 100, 500];
		if (!in_array($limit, $allowedLimits, true)) {
			$limit = 50;
		}

		$page = max(1, $page);
		$searchTerm = $q ? trim($q) : null;

		$totalCount = $this->votingRepository->getLoginLogsCount($unitId, $searchTerm);
		$totalPages = max(1, (int)ceil($totalCount / $limit));
		if ($page > $totalPages) {
			$page = $totalPages;
		}
		$offset = ($page - 1) * $limit;

		$loginLogs = $this->votingRepository->getLoginLogsFiltered($unitId, $searchTerm, $limit, $offset);

		$this->template->loginLogs = $loginLogs;
		$this->template->page = $page;
		$this->template->limit = $limit;
		$this->template->q = $searchTerm;
		$this->template->totalCount = $totalCount;
		$this->template->totalPages = $totalPages;
		$this->template->fromIndex = $totalCount > 0 ? $offset + 1 : 0;
		$this->template->toIndex = min($offset + $limit, $totalCount);
	}

	public function actionDebugMembers(?int $unitId = null): void
	{
		// Z bezpečnostních důvodů zcela zablokováno na produkci
		if (!$this->skautisAuthManager->isTest() && !$this->skautisAuthManager->isDebugRoles()) {
			$this->error('Diagnostický režim je na produkčním prostředí z bezpečnostních důvodů zakázán.', 403);
		}

		$userData = $this->skautisAuthManager->getUserData();
		$userUnitId = (int)($userData['unitId'] ?? 0);
		$targetUnitId = $unitId ?: $userUnitId;

		// Ani ve vývoji nedovolíme zobrazit cizí jednotku, pokud není zapnut debugRoles
		if ($targetUnitId !== $userUnitId && !$this->skautisAuthManager->isDebugRoles()) {
			$this->error('Nemáte oprávnění k diagnostice cizí jednotky.', 403);
		}

		$this->template->userData = $userData;
		$this->template->targetUnitId = $targetUnitId;
		$this->template->debugInfo = $this->skautisAuthManager->debugFetchUnitMembers($targetUnitId);
	}

	protected function createComponentAddMemberForm(): Form
	{
		$form = new Form();

		$options = [];
		foreach ($this->skautisMembers as $m) {
			$label = $m['fullName'];
			if (!empty($m['birthday'])) {
				$label .= ' (* ' . $m['birthday'] . ')';
			}
			if (!empty($m['email'])) {
				$label .= ' (' . $m['email'] . ')';
			}
			$options[$m['personId']] = $label;
		}

		$form->addSelect('personId', 'Vybrat člena z jednotky:', $options)
			->setPrompt('--- Vyberte nebo vyhledejte osobu ---')
			->setRequired('Vyberte prosím osobu ze seznamu.');

		$form->addSubmit('submit', 'Přidat do Rady');

		$form->onSuccess[] = function (Form $form, \stdClass $values): void {
			$userData = $this->skautisAuthManager->getUserData();
			$unitId = (int)$userData['unitId'];
			$personId = (int)$values->personId;

			// Najdeme údaje o osobě ze seznamu
			$name = '';
			$email = null;
			foreach ($this->skautisMembers as $m) {
				if ($m['personId'] === $personId) {
					$name = $m['fullName'];
					$email = $m['email'];
					break;
				}
			}

			// Pokud e-mail chybí, dotážeme se na detail osoby ze SkautISu
			if (empty($email)) {
				$detail = $this->skautisAuthManager->getPersonDetail($personId);
				if (!empty($detail['email'])) {
					$email = $detail['email'];
				}
				if (empty($name) && !empty($detail['fullName'])) {
					$name = $detail['fullName'];
				}
			}

			if (empty($name)) {
				$name = "Osoba #$personId";
			}

			try {
				$this->votingRepository->addCouncilMember($unitId, $personId, $name, $email);
				if ($email) {
					$this->flashMessage("Osoba {$name} byla úspěšně přidána do Rady jednotky s e-mailem {$email}.", 'success');
				} else {
					$this->flashMessage("Osoba {$name} byla úspěšně přidána do Rady jednotky.", 'success');
				}
			} catch (\Nette\Database\UniqueConstraintViolationException $e) {
				$this->flashMessage('Tento uživatel již v radě jednotky je.', 'warning');
			} catch (\Throwable $e) {
				$this->flashMessage('Chyba při ukládání: ' . $e->getMessage(), 'danger');
			}

			$this->redirect('default');
		};

		return $form;
	}

	protected function createComponentSmtpForm(): Form
	{
		$form = new Form();

		$form->addText('host', 'SMTP Server:')
			->setRequired('Zadejte adresu SMTP serveru (např. smtp.gmail.com).');

		$form->addInteger('port', 'Port:')
			->setRequired('Zadejte port (např. 587 nebo 465).');

		$form->addText('username', 'Přihlašovací e-mail / uživatel:')
			->setRequired('Zadejte přihlašovací e-mail.');

		$form->addPassword('password', 'Heslo / Heslo aplikace:')
			->setNullable();

		$form->addSelect('secure', 'Šifrování:', [
			'tls' => 'TLS (Doporučeno pro Gmail na portu 587)',
			'ssl' => 'SSL (Port 465)',
			'empty' => 'Bez šifrování',
		]);

		$form->addEmail('from_email', 'E-mail odesílatele:')
			->setRequired('Zadejte e-mail odesílatele (např. stredisko@skaut.cz).');

		$form->addText('from_name', 'Jméno odesílatele:')
			->setRequired('Zadejte jméno odesílatele (např. Rada 1. střediska).');

		$form->addText('test_recipient', 'Příjemce zkušebního e-mailu:')
			->setNullable();

		$form->addSubmit('test', '🧪 Otestovat a odeslat zkušební e-mail');

		$form->addSubmit('submit', '💾 Uložit konfiguraci');

		$form->onSuccess[] = function (Form $form, array $values): void {
			$userData = $this->skautisAuthManager->getUserData();
			$unitId = (int)$userData['unitId'];
			$post = (array)$this->getHttpRequest()->getPost();
			$existingSmtp = $this->votingRepository->getSmtpSettings($unitId);

			$isTest = $form->isSubmitted() === $form['test'];

			$rawRecipient = $post['test_recipient'] ?? ($values['test_recipient'] ?? null);
			if (empty($rawRecipient)) {
				$rawRecipient = $post['from_email'] ?? ($values['from_email'] ?? ($post['username'] ?? ($values['username'] ?? null)));
			}
			$testRecipient = trim((string)($rawRecipient ?? ''));
			unset($values['test_recipient']);

			// Doplnění chybějících polí z POSTu, pokud by chyběly
			foreach (['host', 'port', 'username', 'password', 'secure', 'from_email', 'from_name'] as $field) {
				if (!isset($values[$field]) && isset($post[$field])) {
					$values[$field] = $post[$field];
				}
			}

			// Pokud je heslo prázdné, použijeme existující uložené heslo z DB
			if (empty($values['password']) && $existingSmtp && !empty($existingSmtp->password)) {
				$values['password'] = $existingSmtp->password;
			}

			if ($isTest) {
				if (empty($values['password'])) {
					$this->flashMessage('Pro odeslání zkušebního e-mailu zadejte heslo (nebo heslo aplikace) k SMTP schránce.', 'warning');
					$this->redirect('this');
					return;
				}

				if (empty($testRecipient) || !filter_var($testRecipient, FILTER_VALIDATE_EMAIL)) {
					$this->flashMessage('Pro odeslání zkušebního e-mailu zadejte platnou e-mailovou adresu příjemce do pole "Příjemce zkušebního e-mailu".', 'warning');
					$this->redirect('this');
					return;
				}

				$testResult = $this->mailSender->testSmtpConnection($values, $testRecipient);
				if ($testResult['success']) {
					$this->flashMessage($testResult['message'], 'success');
				} else {
					$this->flashMessage($testResult['message'], 'danger');
				}
				// Uložíme konfiguraci do DB (saveSmtpSettings heslo bezpečně zašifruje)
				$this->votingRepository->saveSmtpSettings($unitId, $values);
				$this->redirect('this');
				return;
			}

			if (empty($values['password']) && (!$existingSmtp || empty($existingSmtp->password))) {
				$this->flashMessage('Zadejte prosím heslo k SMTP schránce.', 'danger');
				$this->redirect('this');
				return;
			}

			try {
				$this->votingRepository->saveSmtpSettings($unitId, $values);
				$this->flashMessage('SMTP nastavení bylo úspěšně uloženo (heslo je bezpečně zašifrováno).', 'success');
			} catch (\Throwable $e) {
				$this->flashMessage('Chyba při ukládání: ' . $e->getMessage(), 'danger');
				return;
			}

			$this->redirect('Home:default');
		};

		return $form;
	}

	protected function createComponentUnitSettingsForm(): Form
	{
		$form = new Form();

		$form->addCheckbox('allow_custom_end_time', 'Povolit zadávání konkrétního času konce hlasování (např. 14:00, 18:00)')
			->setDefaultValue(false);

		$form->addSubmit('submit', '💾 Uložit nastavení jednotky');

		$form->onSuccess[] = function (Form $form, array $values): void {
			$userData = $this->skautisAuthManager->getUserData();
			$unitId = (int)$userData['unitId'];

			try {
				$this->votingRepository->saveUnitSettings($unitId, $values);
				$this->flashMessage('Nastavení jednotky bylo úspěšně uloženo.', 'success');
			} catch (\Throwable $e) {
				$this->flashMessage('Chyba při ukládání: ' . $e->getMessage(), 'danger');
				return;
			}

			$this->redirect('this');
		};

		return $form;
	}
}
