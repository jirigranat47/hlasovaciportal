<?php

declare(strict_types=1);

namespace App\Presentation\Profile;

use App\Presentation\BasePresenter;
use App\Model\SkautisAuthManager;
use App\Model\VotingRepository;
use App\Model\MailSender;
use Nette\Application\UI\Form;

final class ProfilePresenter extends BasePresenter
{
	public function __construct(
		private SkautisAuthManager $skautisAuthManager,
		private VotingRepository $votingRepository,
		private MailSender $mailSender
	) {
		parent::__construct();
	}

	public function startup(): void
	{
		parent::startup();
		if (!$this->skautisAuthManager->isLoggedIn()) {
			$this->flashMessage('Pro přístup do nastavení profilu se musíte přihlásit.', 'warning');
			$this->redirect('Sign:in', ['backlink' => $this->storeRequest()]);
		}
	}

	public function renderSettings(): void
	{
		$userData = $this->skautisAuthManager->getUserData();
		$personId = (int)$userData['personId'];
		
		// Načtení e-mailu ze SkautIS
		$skautisDetail = $this->skautisAuthManager->getPersonDetail($personId);
		$this->template->skautisEmail = $skautisDetail['email'] ?? null;
		
		// Načtení uloženého vlastního e-mailu
		$userRow = $this->votingRepository->getUserByPersonId($personId);
		$this->template->customEmail = $userRow ? $userRow->custom_email : null;
		
		$this->template->activeEmail = $this->template->customEmail ?: $this->template->skautisEmail;
	}

	protected function createComponentProfileForm(): Form
	{
		$form = new Form;
		
		$form->addEmail('custom_email', 'Vlastní e-mailová adresa:')
			->setNullable()
			->setHtmlAttribute('placeholder', 'Např. jan.novak@skaut.cz');
			
		$form->addSubmit('save', 'Uložit nastavení');
		
		$form->onSuccess[] = [$this, 'profileFormSucceeded'];
		
		$userData = $this->skautisAuthManager->getUserData();
		$userRow = $this->votingRepository->getUserByPersonId((int)$userData['personId']);
		if ($userRow) {
			$form->setDefaults([
				'custom_email' => $userRow->custom_email,
			]);
		}
		
		return $form;
	}

	public function profileFormSucceeded(Form $form, \stdClass $values): void
	{
		$userData = $this->skautisAuthManager->getUserData();
		$personId = (int)$userData['personId'];
		$email = $values->custom_email ?: null;
		
		$this->votingRepository->saveUserCustomEmail($personId, $userData['personName'], $userData['unitName'], $email);
		
		$this->flashMessage('Nastavení e-mailu bylo úspěšně uloženo.', 'success');
		$this->redirect('this');
	}

	public function handleSendTestEmail(): void
	{
		$userData = $this->skautisAuthManager->getUserData();
		$personId = (int)$userData['personId'];
		$unitId = (int)$userData['unitId'];
		
		$userRow = $this->votingRepository->getUserByPersonId($personId);
		$customEmail = $userRow ? $userRow->custom_email : null;
		
		if (!$customEmail) {
			$skautisDetail = $this->skautisAuthManager->getPersonDetail($personId);
			$customEmail = $skautisDetail['email'] ?? null;
		}
		
		if (!$customEmail) {
			$this->flashMessage('Nemáte nastavený žádný e-mail. Nelze odeslat testovací zprávu.', 'danger');
			$this->redirect('this');
		}

		$fromName = $this->votingRepository->getUnitEmailFromName($unitId, $userData['unitName']);
		$smtpSettings = $this->votingRepository->getSmtpSettings($unitId);

		$subject = 'Testovací e-mail - Skautský Hlasovací Portál';
		$body = "<p>Ahoj {$userData['personName']},</p>";
		$body .= "<p>Toto je testovací zpráva ze Skautského Hlasovacího Portálu. Pokud ji čteš, znamená to, že e-mailová adresa je nastavena správně.</p>";
		$body .= "<p>--<br>S pozdravem<br>$fromName</p>";

		$sentCount = 0;
		try {
			$this->mailSender->sendEmail(
				$unitId,
				[$customEmail => $userData['personName']],
				$subject,
				$body,
				$fromName
			);
			$sentCount = 1;
		} catch (\Throwable $e) {
			$sentCount = 0;
		}

		if ($sentCount > 0) {
			$this->flashMessage('Testovací e-mail byl úspěšně odeslán na adresu ' . $customEmail, 'success');
		} else {
			$this->flashMessage('Při odesílání testovacího e-mailu došlo k chybě.', 'danger');
		}
		
		$this->redirect('this');
	}
}
