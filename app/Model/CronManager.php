<?php

declare(strict_types=1);

namespace App\Model;

use Nette\Database\Explorer;

class CronManager
{
	public function __construct(
		private Explorer $database,
		private VotingRepository $votingRepository,
		private MailSender $mailSender,
		private string $baseUrl = 'http://localhost:8000'
	) {}

	public function run(): void
	{
		$this->sendStartNotifications();
		$this->sendEndResults();
	}

	private function sendStartNotifications(): void
	{
		$elections = $this->database->table('elections')
			->where('status', 'published')
			->where('notification_sent', 0)
			->fetchAll();

		foreach ($elections as $el) {
			$smtp = $this->votingRepository->getSmtpSettings($el->unit_id);
			if (!$smtp) {
				continue; // Není nakonfigurováno SMTP, přeskočíme
			}

			$members = $this->votingRepository->getCouncilMembers($el->unit_id);
			$recipients = [];
			foreach ($members as $m) {
				if (!empty($m->email)) {
					$recipients[$m->email] = $m->full_name;
				}
			}

			if (empty($recipients)) {
				// Žádní příjemci s e-mailem, označíme jako odeslané a pokračujeme
				$el->update(['notification_sent' => 1]);
				continue;
			}

			$link = rtrim($this->baseUrl, '/') . '/election/show/' . $el->id;
			$subject = 'Zahájeno hlasování č. ' . $el->resolution_number . ': ' . $el->title;
			
			$body = "<h2>Zahájeno hlasování rady jednotky</h2>";
			$body .= "<p><strong>Číslo usnesení:</strong> " . htmlspecialchars($el->resolution_number) . "</p>";
			$body .= "<p>Bylo zahájeno nové vnitřní hlasování o návrhu: <strong>" . htmlspecialchars($el->title) . "</strong></p>";
			if ($el->proposal_received_date) {
				$body .= "<p>Datum přijetí návrhu: " . $el->proposal_received_date->format('d. m. Y') . "</p>";
			}
			$body .= "<p>Hlasovat můžete nejpozději do: <strong>" . $el->end_date->format('d. m. Y H:i') . "</strong></p>";
			$body .= "<p>Pro zobrazení detailu a odevzdání hlasu klikněte na odkaz níže:<br>";
			$body .= "<a href=\"" . $link . "\">" . $link . "</a></p>";
			$body .= "<hr><p>Toto je automatický e-mail z Hlasovacího Portálu.</p>";

			$this->mailSender->sendEmail($smtp, $recipients, $subject, $body);

			$el->update(['notification_sent' => 1]);
		}
	}

	private function sendEndResults(): void
	{
		$now = new \DateTime();
		$elections = $this->database->table('elections')
			->where('status', 'published')
			->where('end_date <=', $now)
			->where('results_sent', 0)
			->fetchAll();

		foreach ($elections as $el) {
			$smtp = $this->votingRepository->getSmtpSettings($el->unit_id);
			if (!$smtp) {
				continue;
			}

			$members = $this->votingRepository->getCouncilMembers($el->unit_id);
			$recipients = [];
			foreach ($members as $m) {
				if (!empty($m->email)) {
					$recipients[$m->email] = $m->full_name;
				}
			}

			// Vyhodnocení výsledků
			$results = $this->votingRepository->getElectionResults($el->id);
			
			$totalMembers = count($members);
			$votesCount = [
				'Pro' => 0,
				'Proti' => 0,
				'Zdržel se' => 0,
			];

			foreach ($results['options'] as $opt) {
				$votesCount[$opt->title] = $opt->votes_count;
			}

			$proCount = $votesCount['Pro'] ?? 0;
			$isAdopted = $proCount > ($totalMembers / 2);

			$subject = 'Výsledky hlasování č. ' . $el->resolution_number . ': ' . $el->title;

			$body = "<h2>Výsledky hlasování rady jednotky</h2>";
			$body .= "<p><strong>Číslo usnesení:</strong> " . htmlspecialchars($el->resolution_number) . "</p>";
			$body .= "<p>Hlasování o návrhu <strong>" . htmlspecialchars($el->title) . "</strong> bylo ukončeno.</p>";
			
			if ($isAdopted) {
				$body .= "<h3 style=\"color: green;\">Usnesení bylo PŘIJATO</h3>";
			} else {
				$body .= "<h3 style=\"color: red;\">Usnesení NEBYLO PŘIJATO</h3>";
			}

			$body .= "<p><strong>Statistika hlasování:</strong></p>";
			$body .= "<ul>";
			$body .= "<li>Celkový počet členů rady: " . $totalMembers . "</li>";
			$body .= "<li>Hlasovalo PRO: " . $proCount . "</li>";
			$body .= "<li>Hlasovalo PROTI: " . ($votesCount['Proti'] ?? 0) . "</li>";
			$body .= "<li>Zdrželo se: " . ($votesCount['Zdržel se'] ?? 0) . "</li>";
			$body .= "<li>Nehlasovalo: " . ($totalMembers - array_sum($votesCount)) . "</li>";
			$body .= "</ul>";

			$body .= "<p><strong>Jmenný přehled odevzdaných hlasů:</strong></p>";
			$body .= "<table border=\"1\" cellpadding=\"5\" style=\"border-collapse: collapse;\">";
			$body .= "<thead><tr><th>Jméno člena rady</th><th>Jak hlasoval</th></tr></thead>";
			$body .= "<tbody>";
			
			foreach ($members as $m) {
				$vote = $results['votes'][$m->person_id] ?? null;
				$voteText = $vote ? $vote['option_title'] : 'Nehlasoval(a)';
				$body .= "<tr><td>" . htmlspecialchars($m->full_name) . "</td><td>" . $voteText . "</td></tr>";
			}
			$body .= "</tbody></table>";

			$link = rtrim($this->baseUrl, '/') . '/election/show/' . $el->id;
			$body .= "<p>Detail hlasování naleznete zde: <a href=\"" . $link . "\">" . $link . "</a></p>";
			$body .= "<hr><p>Toto je automatický e-mail z Hlasovacího Portálu.</p>";

			if (!empty($recipients)) {
				$this->mailSender->sendEmail($smtp, $recipients, $subject, $body);
			}

			$el->update(['results_sent' => 1]);

			// Auditní log automatického uzavření
			$this->votingRepository->logElectionAudit(
				(int)$el->id,
				0,
				'Systém (Automatické vyhodnocení)',
				'Cron',
				'closed',
				"Hlasování bylo uzavřeno. Výsledek: " . ($isAdopted ? 'PŘIJATO' : 'NEPŘIJATO') . " (Pro: {$proCount}, Proti: " . ($votesCount['Proti'] ?? 0) . ", Zdržel se: " . ($votesCount['Zdržel se'] ?? 0) . ")."
			);
		}
	}

	/**
	 * Odešle notifikaci členům rady o stornování hlasování
	 */
	public function sendCancellationNotification(ActiveRow $election, string $reason): void
	{
		$smtp = $this->votingRepository->getSmtpSettings($election->unit_id);
		if (!$smtp) {
			return;
		}

		$members = $this->votingRepository->getCouncilMembers($election->unit_id);
		$recipients = [];
		foreach ($members as $m) {
			if (!empty($m->email)) {
				$recipients[$m->email] = $m->full_name;
			}
		}

		if (empty($recipients)) {
			return;
		}

		$subject = 'STORNO hlasování č. ' . $election->resolution_number . ': ' . $election->title;

		$body = "<h2 style=\"color: #dc3545;\">Hlasování bylo stornováno</h2>";
		$body .= "<p>Hlasování o usnesení č. <strong>" . htmlspecialchars($election->resolution_number) . "</strong> (" . htmlspecialchars($election->title) . ") bylo správcem stornováno a ukončeno.</p>";
		$body .= "<div style=\"background: #f8d7da; border-left: 4px solid #dc3545; padding: 12px; margin: 15px 0;\">";
		$body .= "<strong>Důvod stornování:</strong><br>" . nl2br(htmlspecialchars($reason));
		$body .= "</div>";
		$body .= "<p>Všechny dosavadní hlasy u tohoto hlasování byly anulovány. Očekávejte prosím případné nové hlasování s opraveným zněním.</p>";

		$link = rtrim($this->baseUrl, '/') . '/election/show/' . $election->id;
		$body .= "<p>Záznam o stornovaném hlasování naleznete zde: <a href=\"" . $link . "\">" . $link . "</a></p>";
		$body .= "<hr><p>Toto je automatický e-mail z Hlasovacího Portálu.</p>";

		$this->mailSender->sendEmail($smtp, $recipients, $subject, $body);
	}
}
