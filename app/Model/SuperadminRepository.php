<?php

declare(strict_types=1);

namespace App\Model;

use Nette\Database\Explorer;

class SuperadminRepository
{
	public function __construct(
		private Explorer $database
	) {}

	/**
	 * Detekuje typ skautské jednotky (kraj, okres, středisko) z názvu a role
	 */
	public static function detectUnitType(?string $unitName, ?string $roleName = null): string
	{
		$combined = mb_strtolower(trim(($unitName ?? '') . ' ' . ($roleName ?? '')), 'UTF-8');
		if (str_contains($combined, 'kraj')) {
			return 'kraj';
		}
		if (str_contains($combined, 'okres')) {
			return 'okres';
		}
		return 'stredisko';
	}

	/**
	 * Vrátí český popisek typu jednotky s ikonou
	 */
	public static function getUnitTypeLabel(string $type): array
	{
		return match ($type) {
			'kraj' => ['label' => 'Kraj', 'badgeBg' => '#fef3c7', 'badgeColor' => '#92400e', 'icon' => 'fa-mountain-sun'],
			'okres' => ['label' => 'Okres', 'badgeBg' => '#e0e7ff', 'badgeColor' => '#3730a3', 'icon' => 'fa-tree'],
			default => ['label' => 'Středisko', 'badgeBg' => '#dcfce7', 'badgeColor' => '#166534', 'icon' => 'fa-campground'],
		};
	}

	/**
	 * Vrátí globální KPI statistiky celého systému
	 */
	public function getGlobalStatistics(): array
	{
		$units = $this->getUnitsList();
		$now = new \DateTime();

		$unitsCount = count($units);
		$unitsByType = ['kraj' => 0, 'okres' => 0, 'stredisko' => 0];
		foreach ($units as $u) {
			$t = $u['unit_type'];
			if (isset($unitsByType[$t])) {
				$unitsByType[$t]++;
			} else {
				$unitsByType['stredisko']++;
			}
		}

		// Celkový počet unikátních členů rad
		$councilMembersCount = (int)$this->database->table('council_members')->count('*');

		// Celkový počet odevzdaných hlasů
		$totalVotesCount = (int)$this->database->table('votes')->count('*');

		// Celkový počet zaznamenaných přihlášení
		$totalLoginsCount = (int)$this->database->table('user_login_logs')->count('*');

		// Počty usnesení dle stavu
		$allElections = $this->database->table('elections')->fetchAll();
		$totalElections = count($allElections);
		$activeElections = 0;
		$closedElections = 0;
		$draftElections = 0;
		$cancelledElections = 0;

		$closedAdopted = 0;
		$closedTurnoutSum = 0.0;
		$closedWithTurnoutCount = 0;

		// Připravíme si mapu počtů členů rad pro jednotky
		$unitMembersMap = $this->database->table('council_members')
			->group('unit_id')
			->select('unit_id, COUNT(*) AS count')
			->fetchPairs('unit_id', 'count');

		// Připravíme si hlasy pro usnesení
		$optionsMap = [];
		foreach ($this->database->table('options') as $opt) {
			$optionsMap[$opt->election_id][$opt->title] = (int)$opt->votes_count;
		}

		foreach ($allElections as $el) {
			$status = (string)$el->status;
			$endDate = $el->end_date;
			$isPastEnd = ($endDate <= $now);
			$isCancelled = ($status === 'cancelled');
			$isClosed = $isCancelled || in_array($status, ['adopted', 'rejected'], true) || ($status === 'published' && $isPastEnd);

			if ($status === 'draft') {
				$draftElections++;
			} elseif ($isCancelled) {
				$cancelledElections++;
				$closedElections++;
			} elseif ($status === 'published' && !$isPastEnd) {
				$activeElections++;
			} elseif ($isClosed) {
				$closedElections++;

				$unitId = (int)$el->unit_id;
				$membersInUnit = (int)($unitMembersMap[$unitId] ?? 0);
				$opts = $optionsMap[$el->id] ?? [];
				$pro = (int)($opts['Pro'] ?? 0);
				$against = (int)($opts['Proti'] ?? 0);
				$abstain = (int)($opts['Zdržel se'] ?? 0);
				$totalVotesInEl = $pro + $against + $abstain;

				$isAdopted = ($status === 'adopted') || (!$isCancelled && $membersInUnit > 0 && ($pro > ($membersInUnit / 2)));
				if ($isAdopted) {
					$closedAdopted++;
				}

				if ($membersInUnit > 0) {
					$turnout = min(100.0, ($totalVotesInEl / $membersInUnit) * 100.0);
					$closedTurnoutSum += $turnout;
					$closedWithTurnoutCount++;
				}
			}
		}

		$validClosedCount = max(0, $closedElections - $cancelledElections);
		$globalSuccessPct = ($validClosedCount > 0) ? round(($closedAdopted / $validClosedCount) * 100.0, 1) : 0.0;
		$globalTurnoutPct = ($closedWithTurnoutCount > 0) ? round($closedTurnoutSum / $closedWithTurnoutCount, 1) : 0.0;

		return [
			'unitsCount' => $unitsCount,
			'unitsByType' => $unitsByType,
			'totalElections' => $totalElections,
			'activeElections' => $activeElections,
			'closedElections' => $closedElections,
			'draftElections' => $draftElections,
			'cancelledElections' => $cancelledElections,
			'councilMembersCount' => $councilMembersCount,
			'totalVotesCount' => $totalVotesCount,
			'totalLoginsCount' => $totalLoginsCount,
			'globalSuccessPct' => $globalSuccessPct,
			'globalTurnoutPct' => $globalTurnoutPct,
		];
	}

	/**
	 * Vrátí strukturovaný seznam všech jednotek s jejich statistikami a aktivitou
	 */
	public function getUnitsList(?string $typeFilter = null, ?string $searchQuery = null, string $sortBy = 'last_activity'): array
	{
		$now = new \DateTime();

		// 1. Shromáždíme všechny unikátní unit_id z existujících tabulek
		$unitIds = [];

		$qElections = $this->database->query('SELECT DISTINCT unit_id FROM elections WHERE unit_id > 0');
		foreach ($qElections as $row) {
			$unitIds[(int)$row->unit_id] = true;
		}

		$qMembers = $this->database->query('SELECT DISTINCT unit_id FROM council_members WHERE unit_id > 0');
		foreach ($qMembers as $row) {
			$unitIds[(int)$row->unit_id] = true;
		}

		$qLogins = $this->database->query('SELECT DISTINCT unit_id FROM user_login_logs WHERE unit_id IS NOT NULL AND unit_id > 0');
		foreach ($qLogins as $row) {
			$unitIds[(int)$row->unit_id] = true;
		}

		$qSettings = $this->database->query('SELECT DISTINCT unit_id FROM unit_settings WHERE unit_id > 0');
		foreach ($qSettings as $row) {
			$unitIds[(int)$row->unit_id] = true;
		}

		if (empty($unitIds)) {
			return [];
		}

		// 2. Načteme metadata o jednotkách z logů (nejnovější záznam pro každou jednotku)
		$loginMetadata = [];
		$logins = $this->database->table('user_login_logs')
			->where('unit_id', array_keys($unitIds))
			->order('logged_at DESC');

		foreach ($logins as $l) {
			$uId = (int)$l->unit_id;
			if (!isset($loginMetadata[$uId])) {
				$loginMetadata[$uId] = [
					'unit_name' => $l->unit_name ?: null,
					'role_name' => $l->role_name ?: null,
					'last_login' => $l->logged_at,
				];
			}
		}

		// 3. Počty členů rad
		$membersCounts = $this->database->table('council_members')
			->where('unit_id', array_keys($unitIds))
			->group('unit_id')
			->select('unit_id, COUNT(*) AS cnt')
			->fetchPairs('unit_id', 'cnt');

		// 4. Nastavení jednotek
		$unitSettingsMap = $this->database->table('unit_settings')
			->where('unit_id', array_keys($unitIds))
			->fetchPairs('unit_id', 'from_name');

		$smtpMap = $this->database->table('smtp_settings')
			->where('unit_id', array_keys($unitIds))
			->fetchPairs('unit_id', 'unit_id');

		// 5. Načteme všechna usnesení a možnosti
		$optionsByElection = [];
		foreach ($this->database->table('options') as $opt) {
			$optionsByElection[$opt->election_id][$opt->title] = (int)$opt->votes_count;
		}

		$electionsByUnit = [];
		foreach ($this->database->table('elections')->order('created_at DESC') as $el) {
			$electionsByUnit[(int)$el->unit_id][] = $el;
		}

		$result = [];

		foreach (array_keys($unitIds) as $uId) {
			$mCount = (int)($membersCounts[$uId] ?? 0);
			$logMeta = $loginMetadata[$uId] ?? null;
			$customFromName = $unitSettingsMap[$uId] ?? null;

			$unitName = $logMeta['unit_name'] ?? $customFromName ?? "Jednotka #$uId";
			$unitType = self::detectUnitType($unitName, $logMeta['role_name'] ?? null);

			$uElections = $electionsByUnit[$uId] ?? [];
			$totalEl = count($uElections);
			$activeEl = 0;
			$closedEl = 0;
			$draftEl = 0;
			$cancelledEl = 0;
			$totalVotesInUnit = 0;

			$closedAdopted = 0;
			$turnoutSum = 0.0;
			$turnoutCount = 0;
			$latestActivity = $logMeta['last_login'] ?? null;

			foreach ($uElections as $el) {
				$status = (string)$el->status;
				$endDate = $el->end_date;
				$createdAt = $el->created_at;
				$isPastEnd = ($endDate <= $now);
				$isCancelled = ($status === 'cancelled');
				$isClosed = $isCancelled || in_array($status, ['adopted', 'rejected'], true) || ($status === 'published' && $isPastEnd);

				if (!$latestActivity || ($createdAt > $latestActivity)) {
					$latestActivity = $createdAt;
				}

				if ($status === 'draft') {
					$draftEl++;
				} elseif ($isCancelled) {
					$cancelledEl++;
					$closedEl++;
				} elseif ($status === 'published' && !$isPastEnd) {
					$activeEl++;
				} elseif ($isClosed) {
					$closedEl++;

					$opts = $optionsByElection[$el->id] ?? [];
					$pro = (int)($opts['Pro'] ?? 0);
					$against = (int)($opts['Proti'] ?? 0);
					$abstain = (int)($opts['Zdržel se'] ?? 0);
					$sumVotes = $pro + $against + $abstain;
					$totalVotesInUnit += $sumVotes;

					$isAdopted = ($status === 'adopted') || (!$isCancelled && $mCount > 0 && ($pro > ($mCount / 2)));
					if ($isAdopted) {
						$closedAdopted++;
					}

					if ($mCount > 0) {
						$t = min(100.0, ($sumVotes / $mCount) * 100.0);
						$turnoutSum += $t;
						$turnoutCount++;
					}
				}
			}

			$validClosed = max(0, $closedEl - $cancelledEl);
			$successPct = ($validClosed > 0) ? round(($closedAdopted / $validClosed) * 100.0, 1) : null;
			$turnoutPct = ($turnoutCount > 0) ? round($turnoutSum / $turnoutCount, 1) : null;

			$item = [
				'unit_id' => $uId,
				'unit_name' => $unitName,
				'unit_type' => $unitType,
				'council_members_count' => $mCount,
				'total_elections' => $totalEl,
				'active_elections' => $activeEl,
				'closed_elections' => $closedEl,
				'draft_elections' => $draftEl,
				'cancelled_elections' => $cancelledEl,
				'total_votes' => $totalVotesInUnit,
				'turnout_pct' => $turnoutPct,
				'success_pct' => $successPct,
				'last_activity' => $latestActivity,
				'has_custom_settings' => isset($unitSettingsMap[$uId]),
				'has_custom_smtp' => isset($smtpMap[$uId]),
			];

			// Aplikace filtrů
			if ($typeFilter && $typeFilter !== 'all' && $unitType !== $typeFilter) {
				continue;
			}

			if ($searchQuery) {
				$needle = mb_strtolower(trim($searchQuery), 'UTF-8');
				$haystack = mb_strtolower($unitName . ' ' . $uId, 'UTF-8');
				if (!str_contains($haystack, $needle)) {
					continue;
				}
			}

			$result[] = $item;
		}

		// Seřadíme: výchozí řazení podle data posledního přihlášení / aktivity (nejnovější nahoře)
		usort($result, function ($a, $b) use ($sortBy) {
			if ($sortBy === 'elections') {
				if ($a['total_elections'] !== $b['total_elections']) {
					return $b['total_elections'] <=> $a['total_elections'];
				}
			} elseif ($sortBy === 'members') {
				if ($a['council_members_count'] !== $b['council_members_count']) {
					return $b['council_members_count'] <=> $a['council_members_count'];
				}
			} elseif ($sortBy === 'turnout') {
				$aT = $a['turnout_pct'] ?? -1.0;
				$bT = $b['turnout_pct'] ?? -1.0;
				if ($aT !== $bT) {
					return $bT <=> $aT;
				}
			} elseif ($sortBy === 'name') {
				$cmp = strcoll($a['unit_name'], $b['unit_name']);
				if ($cmp !== 0) {
					return $cmp;
				}
			}

			// Výchozí řazení nebo sekundární kritérium: podle data poslední aktivity / přihlášení (od nejnovějšího)
			$aTime = $a['last_activity'] ? $a['last_activity']->getTimestamp() : 0;
			$bTime = $b['last_activity'] ? $b['last_activity']->getTimestamp() : 0;
			if ($aTime !== $bTime) {
				return $bTime <=> $aTime;
			}
			return $b['total_elections'] <=> $a['total_elections'];
		});

		return $result;
	}

	/**
	 * Vrátí podrobný detail jedné konkrétní jednotky pro Superadmina
	 */
	public function getUnitDetail(int $unitId): ?array
	{
		$units = $this->getUnitsList(null, (string)$unitId);
		$unitInfo = null;
		foreach ($units as $u) {
			if ($u['unit_id'] === $unitId) {
				$unitInfo = $u;
				break;
			}
		}

		if (!$unitInfo) {
			return null;
		}

		$now = new \DateTime();

		// Členové rady
		$members = $this->database->table('council_members')
			->where('unit_id', $unitId)
			->order('full_name ASC')
			->fetchAll();

		// Usnesení jednotky
		$electionsRows = $this->database->table('elections')
			->where('unit_id', $unitId)
			->order('created_at DESC')
			->fetchAll();

		$optionsMap = [];
		foreach ($this->database->table('options') as $opt) {
			$optionsMap[$opt->election_id][$opt->title] = (int)$opt->votes_count;
		}

		$mCount = (int)$unitInfo['council_members_count'];
		$elections = [];

		foreach ($electionsRows as $el) {
			$status = (string)$el->status;
			$endDate = $el->end_date;
			$isPastEnd = ($endDate <= $now);
			$isCancelled = ($status === 'cancelled');

			$opts = $optionsMap[$el->id] ?? [];
			$pro = (int)($opts['Pro'] ?? 0);
			$against = (int)($opts['Proti'] ?? 0);
			$abstain = (int)($opts['Zdržel se'] ?? 0);
			$votesSum = $pro + $against + $abstain;

			$turnoutPct = ($mCount > 0) ? round(min(100.0, ($votesSum / $mCount) * 100.0), 1) : 0.0;
			$isAdopted = ($status === 'adopted') || (!$isCancelled && $mCount > 0 && ($pro > ($mCount / 2)));

			$elections[] = [
				'id' => $el->id,
				'resolution_number' => $el->resolution_number,
				'title' => $el->title,
				'status' => $status,
				'is_past_end' => $isPastEnd,
				'is_cancelled' => $isCancelled,
				'is_adopted' => $isAdopted,
				'proposal_received_date' => $el->proposal_received_date,
				'end_date' => $el->end_date,
				'created_at' => $el->created_at,
				'pro_count' => $pro,
				'against_count' => $against,
				'abstain_count' => $abstain,
				'total_votes' => $votesSum,
				'turnout_pct' => $turnoutPct,
			];
		}

		// Posledních 20 přihlášení do dané jednotky
		$recentLogins = $this->database->table('user_login_logs')
			->where('unit_id', $unitId)
			->order('logged_at DESC')
			->limit(20)
			->fetchAll();

		// Nastavení jednotky
		$settings = $this->database->table('unit_settings')->where('unit_id', $unitId)->fetch();
		$smtp = $this->database->table('smtp_settings')->where('unit_id', $unitId)->fetch();

		return [
			'info' => $unitInfo,
			'members' => $members,
			'elections' => $elections,
			'recentLogins' => $recentLogins,
			'settings' => $settings,
			'hasCustomSmtp' => ($smtp !== null),
		];
	}

	/**
	 * Vrátí souhrnné analytické rozpadové statistiky pro grafy a trendy
	 */
	public function getTurnoutAndResolutionStats(): array
	{
		// 1. Rozpad odevzdaných hlasů (Pro / Proti / Zdržel se)
		$votesBreakdown = ['pro' => 0, 'against' => 0, 'abstain' => 0];
		foreach ($this->database->table('options') as $opt) {
			if ($opt->title === 'Pro') {
				$votesBreakdown['pro'] += (int)$opt->votes_count;
			} elseif ($opt->title === 'Proti') {
				$votesBreakdown['against'] += (int)$opt->votes_count;
			} elseif ($opt->title === 'Zdržel se') {
				$votesBreakdown['abstain'] += (int)$opt->votes_count;
			}
		}

		// 2. Rozpad výsledků usnesení (Přijato / Nepřijato / Stornováno)
		$units = $this->getUnitsList();
		$outcomes = ['adopted' => 0, 'rejected' => 0, 'cancelled' => 0];
		$turnoutDistribution = [
			'high' => 0,       // >= 90 %
			'good' => 0,       // 75 - 89 %
			'average' => 0,    // 50 - 74 %
			'low' => 0,        // < 50 %
			'no_data' => 0,    // Žádná uzavřená hlasování
		];

		foreach ($units as $u) {
			if ($u['turnout_pct'] === null) {
				$turnoutDistribution['no_data']++;
			} elseif ($u['turnout_pct'] >= 90.0) {
				$turnoutDistribution['high']++;
			} elseif ($u['turnout_pct'] >= 75.0) {
				$turnoutDistribution['good']++;
			} elseif ($u['turnout_pct'] >= 50.0) {
				$turnoutDistribution['average']++;
			} else {
				$turnoutDistribution['low']++;
			}
		}

		$now = new \DateTime();
		$unitMembersMap = $this->database->table('council_members')
			->group('unit_id')
			->select('unit_id, COUNT(*) AS cnt')
			->fetchPairs('unit_id', 'cnt');

		$optionsMap = [];
		foreach ($this->database->table('options') as $opt) {
			$optionsMap[$opt->election_id][$opt->title] = (int)$opt->votes_count;
		}

		foreach ($this->database->table('elections') as $el) {
			$status = (string)$el->status;
			$isPastEnd = ($el->end_date <= $now);
			$isCancelled = ($status === 'cancelled');
			$isClosed = $isCancelled || in_array($status, ['adopted', 'rejected'], true) || ($status === 'published' && $isPastEnd);

			if ($isCancelled) {
				$outcomes['cancelled']++;
			} elseif ($isClosed) {
				$mCount = (int)($unitMembersMap[(int)$el->unit_id] ?? 0);
				$pro = (int)($optionsMap[$el->id]['Pro'] ?? 0);
				$isAdopted = ($status === 'adopted') || ($mCount > 0 && ($pro > ($mCount / 2)));
				if ($isAdopted) {
					$outcomes['adopted']++;
				} else {
					$outcomes['rejected']++;
				}
			}
		}

		// 3. Měsíční trend zakládání usnesení za posledních 6 měsíců
		$monthlyTrends = [];
		for ($i = 5; $i >= 0; $i--) {
			$dt = (new \DateTime())->modify("-$i months");
			$key = $dt->format('Y-m');
			$label = $dt->format('n/Y');
			$monthlyTrends[$key] = [
				'key' => $key,
				'label' => $label,
				'count' => 0,
			];
		}

		$recentElections = $this->database->table('elections')
			->where('created_at >=', (new \DateTime())->modify('-6 months')->format('Y-m-01 00:00:00'))
			->fetchAll();

		foreach ($recentElections as $el) {
			$mKey = $el->created_at->format('Y-m');
			if (isset($monthlyTrends[$mKey])) {
				$monthlyTrends[$mKey]['count']++;
			}
		}

		return [
			'votesBreakdown' => $votesBreakdown,
			'outcomes' => $outcomes,
			'turnoutDistribution' => $turnoutDistribution,
			'monthlyTrends' => array_values($monthlyTrends),
		];
	}
}
