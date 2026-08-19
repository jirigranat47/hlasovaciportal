<?php

declare(strict_types=1);

namespace App\Model;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;

class CronManager
{
	public function __construct(
		private Explorer $database,
		private VotingRepository $votingRepository,
		private MailSender $mailSender,
		private string $baseUrl = 'http://localhost:8000'
	) {}

	/**
	 * Hlavní spouštěcí metoda pro plánovač (Cron)
	 */
	public function run(): void
	{
		$this->sendAllUnnotifiedNewElectionsDigest();
		$this->sendDailyReminders();
		$this->sendDailyResultsDigest();
	}

	/**
	 * Odešle souhrnnou notifikaci o nově publikovaných usneseních pro danou jednotku
	 * (volá se buď ručně správcem z administrace, nebo automaticky nočním cronem)
	 *
	 * @param int $unitId ID jednotky
	 * @param int[]|null $electionIds Volitelný seznam konkrétních ID usnesení (pokud null, vezme všechna unnotified)
	 * @param string|null $unitName Volitelný název jednotky
	 * @return string[] Seznam e-mailů příjemců, na které byla zpráva odeslána
	 */
	public function sendBatchNewElectionsNotification(int $unitId, ?array $electionIds = null, ?string $unitName = null): array
	{
		$query = $this->database->table('elections')
			->where('unit_id', $unitId)
			->where('status', 'published')
			->where('notification_sent', 0);

		if (!empty($electionIds)) {
			$query->where('id', $electionIds);
		}

		$elections = $query->order('created_at ASC')->fetchAll();
		if (empty($elections)) {
			return [];
		}

		$smtp = $this->votingRepository->getSmtpSettings($unitId);
		if (!$smtp) {
			return [];
		}

		$members = $this->votingRepository->getCouncilMembers($unitId);
		$recipients = [];
		foreach ($members as $m) {
			if (!empty($m->email)) {
				$recipients[$m->email] = $m->full_name;
			}
		}

		if (empty($recipients)) {
			return [];
		}

		// Zjistíme název jednotky z DB (historie přihlášení) nebo SMTP nastavení
		$realUnitName = $this->votingRepository->getUnitName((int)$unitId);
		$resolvedUnitName = $unitName ?: ($realUnitName ?: ($smtp->from_name ?: "jednotka #$unitId"));

		// Počet usnesení
		$count = count($elections);
		$subject = "Nová hlasování pro jednotku {$resolvedUnitName}";

		// Sestavení přehledného HTML e-mailu
		$body = "<div style=\"font-family: Arial, sans-serif; color: #1e293b; max-width: 640px; margin: 0 auto; line-height: 1.6;\">";
		$body .= "<div style=\"background: linear-gradient(135deg, #003366 0%, #0055a5 100%); color: white; padding: 20px 24px; border-radius: 8px 8px 0 0;\">";
		$body .= "<h2 style=\"margin: 0; font-size: 1.4rem; color: #ffffff;\">⚜ Nová hlasování rady</h2>";
		$body .= "<p style=\"margin: 5px 0 0 0; font-size: 0.95rem; color: #e2e8f0;\">Jednotka: <strong>" . htmlspecialchars($resolvedUnitName) . "</strong></p>";
		$body .= "</div>";

		$body .= "<div style=\"background: #ffffff; padding: 24px; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 8px 8px;\">";
		$body .= "<p style=\"font-size: 1rem; margin-top: 0;\">Ahoj,<br>byla vyhlášena nová hlasování k <strong>{$count} " . ($count === 1 ? 'usnesení' : ($count < 5 ? 'usnesením' : 'usnesením')) . "</strong> rady vaší jednotky:</p>";

		$body .= "<div style=\"margin: 20px 0;\">";
		foreach ($elections as $el) {
			$link = rtrim($this->baseUrl, '/') . '/election/show/' . $el->id;
			$endDateFormatted = $el->end_date ? $el->end_date->format('d. m. Y (23:59)') : 'neuvedeno';

			$body .= "<div style=\"background: #f8fafc; border: 1px solid #cbd5e1; border-left: 5px solid #0055a5; padding: 14px 18px; border-radius: 6px; margin-bottom: 14px; display: block;\">";
			$body .= "<div style=\"margin-bottom: 6px;\">";
			$body .= "<strong style=\"color: #003366; font-size: 1.05rem;\">Usnesení č. " . htmlspecialchars($el->resolution_number) . "</strong>";
			$body .= "</div>";
			$body .= "<div style=\"font-weight: 600; color: #0f172a; font-size: 1rem; margin-bottom: 8px;\">" . htmlspecialchars($el->title) . "</div>";
			$body .= "<div style=\"font-size: 0.85rem; color: #475569; margin-bottom: 12px;\">⏳ Konec hlasování: <strong>{$endDateFormatted}</strong></div>";
			$body .= "<a href=\"{$link}\" style=\"display: inline-block; background: #0055a5; color: #ffffff; text-decoration: none; padding: 7px 16px; border-radius: 4px; font-size: 0.85rem; font-weight: bold;\">Přejít k hlasování &rarr;</a>";
			$body .= "</div>";
		}
		$body .= "</div>";

		$portalLink = rtrim($this->baseUrl, '/');
		$body .= "<p style=\"text-align: center; margin: 25px 0 10px 0;\">";
		$body .= "<a href=\"{$portalLink}\" style=\"display: inline-block; background: #f4b400; color: #000000; text-decoration: none; padding: 10px 24px; border-radius: 6px; font-weight: bold; font-size: 1rem;\">Otevřít Hlasovací Portál</a>";
		$body .= "</p>";

		$body .= "<hr style=\"border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;\">";
		$body .= "<p style=\"font-size: 0.8rem; color: #94a3b8; margin: 0; text-align: center;\">Toto je automatická zpráva z Hlasovacího Portálu skautských jednotek.</p>";
		$body .= "</div></div>";

		// Odeslání e-mailu
		$this->mailSender->sendEmail($smtp, $recipients, $subject, $body);

		return array_keys($recipients);
	}

	/**
	 * Noční automatický fallback: odešle souhrnné výzvy pro všechny jednotky, kde zůstala neodeslaná nová usnesení.
	 * Běží pouze po půlnoci (mezi 00:00 a 06:00), aby měl správce přes den čas na přípravu a ruční odeslání.
	 */
	public function sendAllUnnotifiedNewElectionsDigest(bool $forceTime = false): int
	{
		$currentHour = (int)date('G');
		// Mimo noční hodiny (00:00 - 05:59) automatický fallback nespouštíme, pokud není vynuceno
		if (!$forceTime && ($currentHour < 0 || $currentHour >= 6)) {
			return 0;
		}

		$unitIds = $this->database->table('elections')
			->where('status', 'published')
			->where('notification_sent', 0)
			->select('DISTINCT unit_id')
			->fetchPairs(null, 'unit_id');

		$totalProcessed = 0;
		foreach ($unitIds as $uId) {
			$uId = (int)$uId;
			$unnotified = $this->votingRepository->getUnnotifiedPublishedElections($uId);
			if (empty($unnotified)) {
				continue;
			}

			$sentEmails = $this->sendBatchNewElectionsNotification($uId);
			$elIds = array_map(fn($r) => (int)$r->id, $unnotified);
			
			$this->votingRepository->markElectionsNotified(
				$elIds,
				$sentEmails,
				0,
				'Cron',
				'Automatický noční souhrn'
			);

			$totalProcessed += count($elIds);
		}

		return $totalProcessed;
	}

	/**
	 * Denní upomínka v 18:00 pro nehlasující členy (den před vypršením termínu).
	 * Spouští se pouze v 18:00 a později (mezi 18:00 a 23:59).
	 */
	public function sendDailyReminders(bool $forceTime = false): int
	{
		$currentHour = (int)date('G');
		// Upomínky odesíláme až od 18:00 dále (aby měl uživatel přesně 24-30 hodin do zítřejší půlnoci)
		if (!$forceTime && $currentHour < 18) {
			return 0;
		}

		$now = new \DateTime();
		// Hlasování končící do 30 hodin (tedy typicky následující den ve 23:59), která ještě nemají odeslanou upomínku
		$limitDate = (new \DateTime())->modify('+30 hours');

		$elections = $this->database->table('elections')
			->where('status', 'published')
			->where('reminder_sent', 0)
			->where('end_date >', $now)
			->where('end_date <=', $limitDate)
			->fetchAll();

		if (empty($elections)) {
			return 0;
		}

		// Seskupíme usnesení podle unit_id
		$byUnit = [];
		foreach ($elections as $el) {
			$byUnit[$el->unit_id][] = $el;
		}

		$processedElectionsCount = 0;

		foreach ($byUnit as $unitId => $unitElections) {
			$smtp = $this->votingRepository->getSmtpSettings((int)$unitId);
			if (!$smtp) {
				continue;
			}

			$members = $this->votingRepository->getCouncilMembers((int)$unitId);
			if (empty($members)) {
				continue;
			}

			$realUnitName = $this->votingRepository->getUnitName((int)$unitId);
			$unitName = $realUnitName ?: ($smtp->from_name ?: "jednotka #$unitId");

			$remindedMembersPerElection = [];

			// Pro každého člena rady zjistíme neodhlasovaná usnesení z této dávky
			foreach ($members as $m) {
				if (empty($m->email)) {
					continue;
				}

				$unvotedForMember = [];
				foreach ($unitElections as $el) {
					$hasVoted = $this->database->table('votes')
						->where('election_id', $el->id)
						->where('person_id', $m->person_id)
						->count() > 0;

					if (!$hasVoted) {
						$unvotedForMember[] = $el;
						$remindedMembersPerElection[$el->id][] = "{$m->full_name} ({$m->email})";
					}
				}

				// Pokud má člen neodhlasovaná usnesení, pošleme mu 1 osobní souhrnnou upomínku
				if (!empty($unvotedForMember)) {
					$count = count($unvotedForMember);
					$subject = "Připomenutí: Zbývá vám odhlasovat {$count} " . ($count === 1 ? 'usnesení' : ($count < 5 ? 'usnesení' : 'usnesení')) . " – {$unitName}";

					$body = "<div style=\"font-family: Arial, sans-serif; color: #1e293b; max-width: 640px; margin: 0 auto; line-height: 1.6;\">";
					$body .= "<div style=\"background: #d97706; color: white; padding: 18px 24px; border-radius: 8px 8px 0 0;\">";
					$body .= "<h2 style=\"margin: 0; font-size: 1.3rem; color: #ffffff;\">⏳ Připomenutí konce hlasování</h2>";
					$body .= "<p style=\"margin: 4px 0 0 0; font-size: 0.9rem; color: #fef3c7;\">Jednotka: <strong>" . htmlspecialchars($unitName) . "</strong></p>";
					$body .= "</div>";

					$body .= "<div style=\"background: #ffffff; padding: 24px; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 8px 8px;\">";
					$body .= "<p style=\"font-size: 1rem; margin-top: 0;\">Ahoj " . htmlspecialchars($m->full_name) . ",<br>připomínáme, že u následujících <strong>{$count}</strong> hlasování rady dosud <strong>neevidujeme váš hlas</strong> a termín brzy vyprší:</p>";

					$body .= "<div style=\"margin: 18px 0;\">";
					foreach ($unvotedForMember as $el) {
						$link = rtrim($this->baseUrl, '/') . '/election/show/' . $el->id;
						$body .= "<div style=\"background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #d97706; padding: 14px 16px; border-radius: 6px; margin-bottom: 14px; display: block;\">";
						$body .= "<div style=\"font-weight: bold; color: #92400e; font-size: 1.05rem; margin-bottom: 6px;\">Usnesení č. " . htmlspecialchars($el->resolution_number) . ": " . htmlspecialchars($el->title) . "</div>";
						$body .= "<div style=\"font-size: 0.85rem; color: #78350f; margin-bottom: 12px;\">Termín do: <strong>" . $el->end_date->format('d. m. Y (23:59)') . "</strong></div>";
						$body .= "<a href=\"{$link}\" style=\"display: inline-block; background: #d97706; color: #ffffff; text-decoration: none; padding: 7px 16px; border-radius: 4px; font-size: 0.85rem; font-weight: bold;\">Odevzdat hlas &rarr;</a>";
						$body .= "</div>";
					}
					$body .= "</div>";

					$portalLink = rtrim($this->baseUrl, '/');
					$body .= "<p style=\"text-align: center; margin: 20px 0;\">";
					$body .= "<a href=\"{$portalLink}\" style=\"display: inline-block; background: #003366; color: #ffffff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-weight: bold;\">Otevřít Hlasovací Portál</a>";
					$body .= "</p>";

					$body .= "<hr style=\"border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;\">";
					$body .= "<p style=\"font-size: 0.8rem; color: #94a3b8; margin: 0; text-align: center;\">Toto je automatická upomínka z Hlasovacího Portálu.</p>";
					$body .= "</div></div>";

					$this->mailSender->sendEmail($smtp, [$m->email => $m->full_name], $subject, $body);
				}
			}

			// Označíme usnesení jako upomenutá a zapíšeme záznam do auditu
			$elIds = array_map(fn($r) => (int)$r->id, $unitElections);
			$this->votingRepository->markElectionsReminderSent($elIds);

			foreach ($unitElections as $el) {
				$reminded = $remindedMembersPerElection[$el->id] ?? [];
				$details = !empty($reminded)
					? "Odeslána denní upomínka nehlasujícím členům rady (" . count($reminded) . "): " . implode(', ', $reminded)
					: "Všichni členové rady již měli v době upomínky odhlasováno (e-mail nebylo nutné posílat).";

				$this->votingRepository->logElectionAudit(
					(int)$el->id,
					0,
					'Cron',
					'Plánovač systému',
					'reminder_sent',
					$details
				);
			}

			$processedElectionsCount += count($elIds);
		}

		return $processedElectionsCount;
	}

	/**
	 * Noční souhrnné vyhodnocení výsledků (Po půlnoci v 00:05)
	 */
	public function sendDailyResultsDigest(): int
	{
		$now = new \DateTime();
		$elections = $this->database->table('elections')
			->where('status', 'published')
			->where('end_date <=', $now)
			->where('results_sent', 0)
			->fetchAll();

		if (empty($elections)) {
			return 0;
		}

		// Seskupíme podle unit_id
		$byUnit = [];
		foreach ($elections as $el) {
			$byUnit[$el->unit_id][] = $el;
		}

		$totalProcessed = 0;

		foreach ($byUnit as $unitId => $unitElections) {
			$smtp = $this->votingRepository->getSmtpSettings((int)$unitId);
			if (!$smtp) {
				continue;
			}

			$members = $this->votingRepository->getCouncilMembers((int)$unitId);
			$recipients = [];
			foreach ($members as $m) {
				if (!empty($m->email)) {
					$recipients[$m->email] = $m->full_name;
				}
			}

			if (empty($recipients)) {
				continue;
			}

			$totalMembers = count($members);
			$realUnitName = $this->votingRepository->getUnitName((int)$unitId);
			$unitName = $realUnitName ?: ($smtp->from_name ?: "jednotka #$unitId");
			$count = count($unitElections);

			$subject = "Výsledky hlasování rady – {$unitName}";

			// Sestavení souhrnného e-mailu s výsledky
			$body = "<div style=\"font-family: Arial, sans-serif; color: #1e293b; max-width: 640px; margin: 0 auto; line-height: 1.6;\">";
			$body .= "<div style=\"background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: white; padding: 20px 24px; border-radius: 8px 8px 0 0;\">";
			$body .= "<h2 style=\"margin: 0; font-size: 1.3rem; color: #ffffff;\">🏁 Výsledky hlasování rady</h2>";
			$body .= "<p style=\"margin: 4px 0 0 0; font-size: 0.9rem; color: #94a3b8;\">Jednotka: <strong>" . htmlspecialchars($unitName) . "</strong> | Počet ukončených usnesení: {$count}</p>";
			$body .= "</div>";

			$body .= "<div style=\"background: #ffffff; padding: 24px; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 8px 8px;\">";
			$body .= "<p style=\"font-size: 1rem; margin-top: 0;\">Ahoj,<br>byla uzavřena a vyhodnocena následující hlasování rady jednotky:</p>";

			$body .= "<div style=\"margin: 18px 0;\">";

			foreach ($unitElections as $el) {
				$results = $this->votingRepository->getElectionResults($el->id);
				$votesCount = [
					'Pro' => 0,
					'Proti' => 0,
					'Zdržel se' => 0,
				];

				foreach ($results['options'] as $opt) {
					if (isset($votesCount[$opt->title])) {
						$votesCount[$opt->title] = (int)$opt->votes_count;
					}
				}

				$proCount = $votesCount['Pro'];
				// Schváleno nadpoloviční většinou všech členů rady
				$isAdopted = $totalMembers > 0 && ($proCount > ($totalMembers / 2));
				$notVotedCount = max(0, $totalMembers - array_sum($votesCount));

				$link = rtrim($this->baseUrl, '/') . '/election/show/' . $el->id;
				$historyLink = rtrim($this->baseUrl, '/') . '/election/history/' . $el->id;

				$statusColor = $isAdopted ? '#16a34a' : '#dc2626';
				$statusBg = $isAdopted ? '#dcfce7' : '#fee2e2';
				$statusText = $isAdopted ? '✓ PŘIJATO' : '✕ NEPŘIJATO';

				$body .= "<div style=\"background: #f8fafc; border: 1px solid #e2e8f0; border-left: 5px solid {$statusColor}; padding: 14px 18px; border-radius: 6px; margin-bottom: 16px; display: block;\">";
				$body .= "<table style=\"width: 100%; border-collapse: collapse; margin-bottom: 6px;\"><tr>";
				$body .= "<td style=\"vertical-align: middle;\"><strong style=\"font-size: 1.05rem; color: #0f172a;\">Usnesení č. " . htmlspecialchars($el->resolution_number) . "</strong></td>";
				$body .= "<td style=\"text-align: right; vertical-align: middle;\"><span style=\"background: {$statusBg}; color: {$statusColor}; font-weight: bold; font-size: 0.85rem; padding: 4px 10px; border-radius: 12px; display: inline-block;\">{$statusText}</span></td>";
				$body .= "</tr></table>";
				$body .= "<div style=\"font-weight: 600; color: #334155; font-size: 1rem; margin-bottom: 10px;\">" . htmlspecialchars($el->title) . "</div>";

				// Hlasovací statistika
				$body .= "<div style=\"font-size: 0.85rem; background: #ffffff; padding: 8px 12px; border-radius: 4px; border: 1px solid #e2e8f0; margin-bottom: 12px;\">";
				$body .= "<span>Pro: <strong>{$proCount}</strong></span> &bull; ";
				$body .= "<span>Proti: <strong>" . ($votesCount['Proti'] ?? 0) . "</strong></span> &bull; ";
				$body .= "<span>Zdržel se: <strong>" . ($votesCount['Zdržel se'] ?? 0) . "</strong></span> &bull; ";
				$body .= "<span>Nehlasovalo: <strong>{$notVotedCount}</strong></span>";
				$body .= "</div>";

				$body .= "<div style=\"font-size: 0.85rem;\">";
				$body .= "<a href=\"{$link}\" style=\"color: #0055a5; text-decoration: none; font-weight: bold; margin-right: 14px;\">Zobrazit detail usnesení &rarr;</a>";
				$body .= "<a href=\"{$historyLink}\" style=\"color: #64748b; text-decoration: none;\">Historie změn</a>";
				$body .= "</div>";
				$body .= "</div>";

				// Záznam do auditu usnesení
				$this->votingRepository->logElectionAudit(
					(int)$el->id,
					0,
					'Cron',
					'Plánovač systému',
					'closed',
					"Hlasování bylo uzavřeno. Výsledek: " . ($isAdopted ? 'PŘIJATO' : 'NEPŘIJATO') . " (Pro: {$proCount}, Proti: " . ($votesCount['Proti'] ?? 0) . ", Zdržel se: " . ($votesCount['Zdržel se'] ?? 0) . ", Celkem členů: {$totalMembers})."
				);

				$el->update([
					'status' => $isAdopted ? 'adopted' : 'rejected',
					'results_sent' => 1,
				]);
				$totalProcessed++;
			}

			$body .= "</div>";

			$portalLink = rtrim($this->baseUrl, '/');
			$body .= "<p style=\"text-align: center; margin: 25px 0 10px 0;\">";
			$body .= "<a href=\"{$portalLink}\" style=\"display: inline-block; background: #003366; color: #ffffff; text-decoration: none; padding: 10px 24px; border-radius: 6px; font-weight: bold;\">Otevřít Hlasovací Portál</a>";
			$body .= "</p>";

			$body .= "<hr style=\"border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;\">";
			$body .= "<p style=\"font-size: 0.8rem; color: #94a3b8; margin: 0; text-align: center;\">Toto je automatické vyhodnocení výsledků z Hlasovacího Portálu.</p>";
			$body .= "</div></div>";

			$this->mailSender->sendEmail($smtp, $recipients, $subject, $body);
		}

		return $totalProcessed;
	}

	/**
	 * Odešle notifikaci členům rady o stornování hlasování a vrátí seznam příjemců
	 * @return string[] pole e-mailových adres příjemců
	 */
	public function sendCancellationNotification(ActiveRow $election, string $reason): array
	{
		$smtp = $this->votingRepository->getSmtpSettings($election->unit_id);
		if (!$smtp) {
			return [];
		}

		$members = $this->votingRepository->getCouncilMembers($election->unit_id);
		$recipients = [];
		foreach ($members as $m) {
			if (!empty($m->email)) {
				$recipients[$m->email] = $m->full_name;
			}
		}

		if (empty($recipients)) {
			return [];
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

		return array_keys($recipients);
	}
}
