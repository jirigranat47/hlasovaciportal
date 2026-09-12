<?php

declare(strict_types=1);

namespace App\Model;

use Nette\Mail\Message;
use Nette\Mail\SmtpMailer;
use Nette\Database\Table\ActiveRow;

class MailSender
{
	public function __construct(
		private VotingRepository $votingRepository
	) {}

	/**
	 * Odešle e-mail na základě SMTP nastavení jednotky
	 *
	 * @param ActiveRow $smtp Nastavení SMTP ze smtp_settings
	 * @param array $recipients Pole příjemců [email => name]
	 * @param string $subject Předmět e-mailu
	 * @param string $bodyHtml HTML tělo e-mailu
	 */
	public function sendEmail(ActiveRow $smtp, array $recipients, string $subject, string $bodyHtml): void
	{
		if (empty($recipients)) {
			return;
		}

		$password = $this->votingRepository->decryptPassword($smtp->password);
		$secure = $smtp->secure !== 'empty' && !empty($smtp->secure) ? $smtp->secure : null;

		$mailer = new SmtpMailer(
			host: $smtp->host,
			username: $smtp->username,
			password: $password,
			port: (int)$smtp->port,
			encryption: $secure,
		);

		foreach ($recipients as $email => $name) {
			if (empty($email)) {
				continue;
			}

			$message = new Message();
			$message->setFrom($smtp->from_email, $smtp->from_name)
				->addTo($email, $name)
				->setSubject($subject)
				->setHtmlBody($bodyHtml);

			if (!empty($smtp->from_email)) {
				$message->addReplyTo($smtp->from_email, $smtp->from_name);
			}

			try {
				$mailer->send($message);
			} catch (\Throwable $e) {
				// Chybu zalogujeme, ale pokračujeme na další e-maily
				trigger_error('Chyba odesílání e-mailu přes SMTP jednotky ' . $smtp->unit_id . ': ' . $e->getMessage(), E_USER_WARNING);
			}
		}
	}

	/**
	 * Otestuje SMTP spojení a odešle testovací e-mail
	 */
	public function testSmtpConnection(array $smtpData, ?string $recipientEmail): array
	{
		if (empty($recipientEmail)) {
			return [
				'success' => false,
				'message' => 'Není zadána e-mailová adresa příjemce pro testovací e-mail.',
			];
		}

		$password = $this->votingRepository->decryptPassword($smtpData['password'] ?? '');
		$secure = !empty($smtpData['secure']) && $smtpData['secure'] !== 'empty' ? $smtpData['secure'] : null;

		try {
			$mailer = new SmtpMailer(
				host: $smtpData['host'] ?? 'smtp.gmail.com',
				username: $smtpData['username'] ?? '',
				password: $password,
				port: (int)($smtpData['port'] ?? 587),
				encryption: $secure,
			);
			$message = new Message();
			$fromEmail = !empty($smtpData['from_email']) ? $smtpData['from_email'] : $smtpData['username'];
			$fromName = !empty($smtpData['from_name']) ? $smtpData['from_name'] : 'Skautský Hlasovací Portál';

			$message->setFrom($fromEmail, $fromName)
				->addTo($recipientEmail)
				->setSubject('🧪 Testovací e-mail z Hlasovacího Portálu')
				->setHtmlBody('<h2>Test SMTP spojení byl úspěšný!</h2><p>Tento e-mail potvrzuje, že SMTP server vaší jednotky je správně nakonfigurován a připraven k odesílání notifikací.</p><p>Odesláno z: <strong>' . htmlspecialchars($fromEmail) . '</strong> (' . htmlspecialchars($fromName) . ')<br>Čas odeslání: ' . (new \DateTime())->format('d. m. Y H:i:s') . '</p>');

			if (!empty($fromEmail)) {
				$message->addReplyTo($fromEmail, $fromName);
			}

			$mailer->send($message);
			return [
				'success' => true,
				'message' => "Testovací e-mail byl úspěšně odeslán z adresy '{$fromEmail}' ({$fromName}) na adresu '{$recipientEmail}'.",
			];
		} catch (\Throwable $e) {
			$errorMsg = $e->getMessage();
			if (str_contains($errorMsg, 'Username and Password not accepted') || str_contains($errorMsg, '535-5.7.8') || str_contains($errorMsg, '535 5.7.8')) {
				$errorMsg = "Google / Gmail odmítl přihlášení (chyba 535). Ujistěte se, že používáte vygenerované 'Heslo aplikace' (Google App Password) se zapnutým 2fázovým ověřením, nikoliv vaše běžné osobní heslo k účtu.";
			} elseif (str_contains($errorMsg, 'Connection timed out') || str_contains($errorMsg, 'Connection refused')) {
				$errorMsg = "Nepodařilo se navázat spojení se serverem {$smtpData['host']}:{$smtpData['port']}. Zkontrolujte adresu hostitele a port.";
			}
			return [
				'success' => false,
				'message' => "Chyba při odesílání: {$errorMsg}",
			];
		}
	}
}
