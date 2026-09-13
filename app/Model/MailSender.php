<?php

declare(strict_types=1);

namespace App\Model;

use Nette\Mail\Message;
use Nette\Mail\SmtpMailer;
use Nette\Database\Table\ActiveRow;

class MailSender
{
	public function __construct(
		private VotingRepository $votingRepository,
		private array $smtpConfig = []
	) {}

	/**
	 * Zda je centrální SMTP nakonfigurováno
	 */
	public function isConfigured(): bool
	{
		return !empty($this->smtpConfig['host'])
			&& !empty($this->smtpConfig['username'])
			&& !empty($this->smtpConfig['password'])
			&& $this->smtpConfig['password'] !== 'zmente_v_local_neon';
	}

	/**
	 * Vytvoří instanci SMTP maileru na základě centrální konfigurace
	 */
	private function createMailer(?array $customConfig = null): SmtpMailer
	{
		$config = $customConfig ?: $this->smtpConfig;
		$host = $config['host'] ?? 'smtp.gmail.com';
		$port = (int)($config['port'] ?? 587);
		$username = $config['username'] ?? 'hlasovani@skaut.cz';
		$password = $config['password'] ?? '';
		$secure = !empty($config['secure']) && $config['secure'] !== 'empty' ? $config['secure'] : null;

		return new SmtpMailer(
			host: $host,
			username: $username,
			password: $password,
			port: $port,
			encryption: $secure,
		);
	}

	/**
	 * Odešle e-mail na základě centrálního účtu a jména jednotky
	 *
	 * @param mixed $smtpOrUnitId ID jednotky (int), ActiveRow nebo array
	 * @param array $recipients Pole příjemců [email => name]
	 * @param string $subject Předmět e-mailu
	 * @param string $bodyHtml HTML tělo e-mailu
	 * @param string|null $customFromName Volitelné vlastní jméno odesílatele
	 */
	public function sendEmail(mixed $smtpOrUnitId, array $recipients, string $subject, string $bodyHtml, ?string $customFromName = null): void
	{
		if (empty($recipients)) {
			return;
		}

		$unitId = 0;
		if (is_int($smtpOrUnitId) || is_numeric($smtpOrUnitId)) {
			$unitId = (int)$smtpOrUnitId;
		} elseif ($smtpOrUnitId instanceof ActiveRow) {
			$unitId = (int)($smtpOrUnitId->unit_id ?? 0);
		} elseif (is_array($smtpOrUnitId)) {
			$unitId = (int)($smtpOrUnitId['unit_id'] ?? 0);
		}

		$fromName = $customFromName ?: ($unitId > 0 ? $this->votingRepository->getUnitEmailFromName($unitId) : ($this->smtpConfig['defaultFromName'] ?? 'Skautský Hlasovací Portál'));
		$fromEmail = $this->smtpConfig['fromEmail'] ?? ($this->smtpConfig['username'] ?? 'hlasovani@skaut.cz');

		try {
			$mailer = $this->createMailer();
		} catch (\Throwable $e) {
			trigger_error('Chyba inicializace SMTP maileru: ' . $e->getMessage(), E_USER_WARNING);
			return;
		}

		foreach ($recipients as $email => $name) {
			if (empty($email)) {
				continue;
			}

			$message = new Message();
			$message->setFrom($fromEmail, $fromName)
				->addTo($email, $name)
				->setSubject($subject)
				->setHtmlBody($bodyHtml);

			if (!empty($fromEmail)) {
				$message->addReplyTo($fromEmail, $fromName);
			}

			try {
				$mailer->send($message);
			} catch (\Throwable $e) {
				// Chybu zalogujeme, ale pokračujeme na další e-maily
				trigger_error('Chyba odesílání e-mailu pro jednotku ' . $unitId . ': ' . $e->getMessage(), E_USER_WARNING);
			}
		}
	}

	/**
	 * Otestuje centrální SMTP odesílání pro konkrétní jednotku
	 */
	public function testUnitSmtp(int $unitId, ?string $recipientEmail): array
	{
		if (empty($recipientEmail)) {
			return [
				'success' => false,
				'message' => 'Není zadána e-mailová adresa příjemce pro zkušební e-mail.',
			];
		}

		$fromName = $this->votingRepository->getUnitEmailFromName($unitId);
		$fromEmail = $this->smtpConfig['fromEmail'] ?? ($this->smtpConfig['username'] ?? 'hlasovani@skaut.cz');

		try {
			$mailer = $this->createMailer();
			$message = new Message();
			$message->setFrom($fromEmail, $fromName)
				->addTo($recipientEmail)
				->setSubject('🧪 Zkušební e-mail z Hlasovacího Portálu')
				->setHtmlBody('<h2>Test odesílání e-mailů byl úspěšný!</h2><p>Tento e-mail potvrzuje, že odesílání notifikací z centrálního účtu portálu je správně nastaveno a funkční.</p><p>Odesílatel: <strong>' . htmlspecialchars($fromName) . '</strong> &lt;' . htmlspecialchars($fromEmail) . '&gt;<br>Příjemce: ' . htmlspecialchars($recipientEmail) . '<br>Čas odeslání: ' . (new \DateTime())->format('d. m. Y H:i:s') . '</p>');

			if (!empty($fromEmail)) {
				$message->addReplyTo($fromEmail, $fromName);
			}

			$mailer->send($message);
			return [
				'success' => true,
				'message' => "Zkušební e-mail byl úspěšně odeslán z centrální adresy '{$fromEmail}' s odesílatelem '{$fromName}' na adresu '{$recipientEmail}'.",
			];
		} catch (\Throwable $e) {
			$errorMsg = $e->getMessage();
			if (str_contains($errorMsg, 'Username and Password not accepted') || str_contains($errorMsg, '535-5.7.8') || str_contains($errorMsg, '535 5.7.8')) {
				$errorMsg = "Google / Gmail odmítl přihlášení centrálního účtu (chyba 535). Ujistěte se, že je v config/local.neon nastaveno správné 'Heslo aplikace' pro hlasovani@skaut.cz.";
			} elseif (str_contains($errorMsg, 'Connection timed out') || str_contains($errorMsg, 'Connection refused')) {
				$errorMsg = "Nepodařilo se navázat spojení se serverem " . ($this->smtpConfig['host'] ?? '') . ":" . ($this->smtpConfig['port'] ?? '') . ".";
			}
			return [
				'success' => false,
				'message' => "Chyba při odesílání: {$errorMsg}",
			];
		}
	}

	/**
	 * Původní metoda pro otestování libovolného SMTP spojení (zpětná kompatibilita)
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
