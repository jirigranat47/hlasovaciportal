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
		private VotingRepository $votingRepository
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
		
		// Načteme členy jednotky ze Skautisu pro dropdown
		$this->skautisMembers = $this->skautisAuthManager->getUnitMembers();
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
			$this['smtpForm']->setDefaults($smtp->toArray());
		}
	}

	protected function createComponentAddMemberForm(): Form
	{
		$form = new Form();

		$options = [];
		foreach ($this->skautisMembers as $m) {
			$options[$m['personId']] = $m['fullName'] . ($m['email'] ? ' (' . $m['email'] . ')' : '');
		}

		$form->addSelect('personId', 'Vybrat člena z jednotky:', $options)
			->setPrompt('--- Vyberte osobu ---')
			->setRequired('Vyberte prosím osobu ze seznamu.');

		$form->addSubmit('submit', 'Přidat do Rady');

		$form->onSuccess[] = function (Form $form, \stdClass $values): void {
			$userData = $this->skautisAuthManager->getUserData();
			$unitId = (int)$userData['unitId'];
			$personId = (int)$values->personId;

			// Najdeme údaje o osobě
			$name = '';
			$email = null;
			foreach ($this->skautisMembers as $m) {
				if ($m['personId'] === $personId) {
					$name = $m['fullName'];
					$email = $m['email'];
					break;
				}
			}

			try {
				$this->votingRepository->addCouncilMember($unitId, $personId, $name, $email);
				$this->flashMessage('Osoba byla úspěšně přidána do Rady jednotky.', 'success');
			} catch (\Nette\Database\UniqueConstraintViolationException $e) {
				$this->flashMessage('Tento uživatel již v radě jednotky je.', 'warning');
			} catch (\Throwable $e) {
				$this->flashMessage('Chyba při ukládání: ' . $e->getMessage(), 'danger');
			}

			$this->redirect('default');
		};

		return $form;
	}

	protected function createComponentAddManualMemberForm(): Form
	{
		$form = new Form();
		$form->addInteger('personId', 'SkautIS Person ID:')
			->setRequired('Zadejte číselné Person ID.');
		$form->addText('fullName', 'Jméno a příjmení:')
			->setRequired('Zadejte jméno osoby.');
		$form->addEmail('email', 'E-mail (volitelné):')
			->setNullable();
		$form->addSubmit('submit', 'Přidat člena ručně');

		$form->onSuccess[] = function (Form $form, \stdClass $values): void {
			$userData = $this->skautisAuthManager->getUserData();
			$unitId = (int)$userData['unitId'];

			try {
				$this->votingRepository->addCouncilMember($unitId, (int)$values->personId, $values->fullName, $values->email);
				$this->flashMessage("Osoba {$values->fullName} byla úspěšně přidána do Rady jednotky.", 'success');
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
			->setRequired('Zadejte adresu SMTP serveru (např. smtp.skaut.cz).');

		$form->addInteger('port', 'Port:')
			->setRequired('Zadejte port (např. 587 nebo 465).');

		$form->addText('username', 'Přihlašovací jméno:')
			->setRequired('Zadejte přihlašovací jméno k SMTP.');

		$form->addPassword('password', 'Heslo:')
			->setRequired('Zadejte heslo k SMTP.');

		$form->addSelect('secure', 'Šifrování:', [
			'tls' => 'TLS',
			'ssl' => 'SSL',
			'empty' => 'Bez šifrování',
		]);

		$form->addEmail('from_email', 'E-mail odesílatele:')
			->setRequired('Zadejte e-mail odesílatele (např. stredisko@skaut.cz).');

		$form->addText('from_name', 'Jméno odesílatele:')
			->setRequired('Zadejte jméno odesílatele (např. 1. středisko Junáka).');

		$form->addSubmit('submit', 'Uložit nastavení');

		$form->onSuccess[] = function (Form $form, array $values): void {
			$userData = $this->skautisAuthManager->getUserData();
			$unitId = (int)$userData['unitId'];

			try {
				$this->votingRepository->saveSmtpSettings($unitId, $values);
				$this->flashMessage('SMTP nastavení bylo úspěšně uloženo.', 'success');
			} catch (\Throwable $e) {
				$this->flashMessage('Chyba při ukládání: ' . $e->getMessage(), 'danger');
				return;
			}

			$this->redirect('Home:default');
		};

		return $form;
	}
}
