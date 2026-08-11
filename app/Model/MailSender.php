<?php

declare(strict_types=1);

namespace App\Model;

use Nette\Mail\Message;
use Nette\Mail\SmtpMailer;
use Nette\Database\Table\ActiveRow;

class MailSender
{
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

		$config = [
			'host' => $smtp->host,
			'port' => $smtp->port,
			'username' => $smtp->username,
			'password' => $smtp->password,
			'secure' => $smtp->secure !== 'empty' && !empty($smtp->secure) ? $smtp->secure : null,
		];

		// Pro vývoj/testování v Dockeru, pokud host je localhost/localhost SMTP
		// Nette SmtpMailer se o to postará.
		$mailer = new SmtpMailer($config);

		foreach ($recipients as $email => $name) {
			if (empty($email)) {
				continue;
			}

			$message = new Message();
			$message->setFrom($smtp->from_email, $smtp->from_name)
				->addTo($email, $name)
				->setSubject($subject)
				->setHtmlBody($bodyHtml);

			try {
				$mailer->send($message);
			} catch (\Throwable $e) {
				// Chybu zalogujeme, ale pokračujeme na další e-maily
				trigger_error('Chyba odesílání e-mailu přes SMTP jednotky ' . $smtp->unit_id . ': ' . $e->getMessage(), E_USER_WARNING);
			}
		}
	}
}
