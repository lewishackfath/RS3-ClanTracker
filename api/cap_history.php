<?php
declare(strict_types=1);

require_once __DIR__ . '/_db.php';

function tracker_ch_get_param(string $key, int $maxLen = 64): string {
    $v = trim((string)($_GET[$key] ?? ''));
    if ($v === '') return '';
    if (mb_strlen($v) > $maxLen) $v = mb_substr($v, 0, $maxLen);
    return $v;
}

function tracker_ch_normalise_rank(?string $rank): string {
    $rank = strtolower(trim((string)$rank));
    $rank = preg_replace('/[^a-z0-9]+/', ' ', $rank) ?: '';
    $rank = preg_replace('/\s+/', ' ', $rank) ?: '';
    return trim($rank);
}

function tracker_ch_rank_sort_index(?string $rank): int {
    static $map = null;
    if ($map === null) {
        $order = [
            'guest',
            'recruit',
            'corporal',
            'sergeant',
            'lieutenant',
            'captain',
            'general',
            'admin',
            'organiser',
            'coordinator',
            'overseer',
            'deputy owner',
            'owner',
        ];
        $map = array_flip($order);
    }

    $key = tracker_ch_normalise_rank($rank);
    return array_key_exists($key, $map) ? (int)$map[$key] : -1;
}

function tracker_ch_compare_ranks_desc(string $a, string $b): int {
    $av = tracker_ch_rank_sort_index($a);
    $bv = tracker_ch_rank_sort_index($b);
    if ($av !== $bv) return $bv <=> $av;
    return strcasecmp($a, $b);
}

function tracker_ch_compare_members_by_rank_desc(array $a, array $b): int {
    $rankCompare = tracker_ch_compare_ranks_desc((string)($a['rank_name'] ?? ''), (string)($b['rank_name'] ?? ''));
    if ($rankCompare !== 0) return $rankCompare;
    return strcasecmp((string)($a['rsn'] ?? ''), (string)($b['rsn'] ?? ''));
}

function tracker_ch_rank_icon(?string $rank): string {
    $key = tracker_ch_normalise_rank($rank);
    $key = preg_replace('/[^a-z0-9]+/', '', $key) ?: '';

    $fileMap = [
        'owner' => 'Owner',
        'deputyowner' => 'DeputyOwner',
        'overseer' => 'Overseer',
        'coordinator' => 'Coordinator',
        'organiser' => 'Organiser',
        'organizer' => 'Organiser',
        'admin' => 'Admin',
        'general' => 'General',
        'captain' => 'Captain',
        'lieutenant' => 'Lieutenant',
        'sergeant' => 'Sergeant',
        'corporal' => 'Corporal',
        'recruit' => 'Recruit',
        'guest' => 'Guest',
    ];

    return isset($fileMap[$key]) ? 'assets/ranks/' . $fileMap[$key] . '_Clan_Rank.png' : '';
}


function tracker_ch_week_start_local(DateTimeImmutable $date): DateTimeImmutable {
    // ISO weekday: Monday=1 through Sunday=7. Calendar arithmetic preserves DST.
    $daysSinceMonday = (int)$date->format('N') - 1;
    return $date->modify('-' . $daysSinceMonday . ' days')->setTime(0, 0, 0, 0);
}

function tracker_ch_citadel_per_week_52(PDO $pdo, int $clanId, array $clan): array {
    $tzName = (string)($clan['timezone'] ?? 'UTC');
    try {
        $tz = new DateTimeZone($tzName);
    } catch (Throwable $e) {
        $tzName = 'UTC';
        $tz = new DateTimeZone('UTC');
    }

    $utc = new DateTimeZone('UTC');
    $currentWeekStartLocal = tracker_ch_week_start_local(new DateTimeImmutable('now', $tz));
    $firstWeekStartLocal = $currentWeekStartLocal->modify('-51 weeks');
    $lastWeekEndLocal = $currentWeekStartLocal->modify('+1 week');

    $weeks = [];
    for ($i = 0; $i < 52; $i++) {
        $weekStartLocal = $firstWeekStartLocal->modify('+' . $i . ' weeks');
        $weekEndLocal = $weekStartLocal->modify('+1 week');
        $weekStartUtc = $weekStartLocal->setTimezone($utc);
        $key = $weekStartUtc->format('Y-m-d H:i:s');

        $weeks[$key] = [
            'week_start_utc' => $weekStartUtc->format('Y-m-d H:i:s'),
            'week_end_utc' => $weekEndLocal->setTimezone($utc)->format('Y-m-d H:i:s'),
            'week_start_local' => $weekStartLocal->format('Y-m-d H:i:s'),
            'week_end_local' => $weekEndLocal->format('Y-m-d H:i:s'),
            'date' => $weekStartLocal->format('Y-m-d'),
            'label' => $weekStartLocal->format('j M'),
            'visit_count' => 0,
            'cap_count' => 0,
            // Backwards-compatible alias for any older frontend code that expected caps in `count`.
            'count' => 0,
        ];
    }

    $startUtc = $firstWeekStartLocal->setTimezone($utc)->format('Y-m-d H:i:s');
    $endUtc = $lastWeekEndLocal->setTimezone($utc)->format('Y-m-d H:i:s');

    $capStmt = $pdo->prepare("
        SELECT capped_at_utc, COUNT(*) AS cap_count
        FROM member_caps c
        JOIN members m ON m.id = c.member_id AND m.clan_id = c.clan_id
        WHERE c.clan_id = :cid
          AND LOWER(TRIM(COALESCE(m.rank_name, ''))) <> 'guest'
          AND capped_at_utc >= :start_utc
          AND capped_at_utc < :end_utc
        GROUP BY capped_at_utc
        ORDER BY capped_at_utc ASC
    ");
    $capStmt->execute([
        ':cid' => $clanId,
        ':start_utc' => $startUtc,
        ':end_utc' => $endUtc,
    ]);

    while ($row = $capStmt->fetch()) {
        $raw = (string)($row['capped_at_utc'] ?? '');
        if ($raw === '') continue;
        try {
            $key = tracker_ch_week_start_local((new DateTimeImmutable($raw, $utc))->setTimezone($tz))
                ->setTimezone($utc)->format('Y-m-d H:i:s');
            if (isset($weeks[$key])) {
                $count = (int)($row['cap_count'] ?? 0);
                $weeks[$key]['cap_count'] += $count;
                $weeks[$key]['count'] += $count;
            }
        } catch (Throwable $e) {}
    }

    $visitStmt = $pdo->prepare("
        SELECT visited_at_utc, COUNT(*) AS visit_count
        FROM member_citadel_visits v
        JOIN members m ON m.id = v.member_id AND m.clan_id = v.clan_id
        WHERE v.clan_id = :cid
          AND LOWER(TRIM(COALESCE(m.rank_name, ''))) <> 'guest'
          AND visited_at_utc >= :start_utc
          AND visited_at_utc < :end_utc
        GROUP BY visited_at_utc
        ORDER BY visited_at_utc ASC
    ");
    $visitStmt->execute([
        ':cid' => $clanId,
        ':start_utc' => $startUtc,
        ':end_utc' => $endUtc,
    ]);

    while ($row = $visitStmt->fetch()) {
        $raw = (string)($row['visited_at_utc'] ?? '');
        if ($raw === '') continue;
        try {
            $key = tracker_ch_week_start_local((new DateTimeImmutable($raw, $utc))->setTimezone($tz))
                ->setTimezone($utc)->format('Y-m-d H:i:s');
            if (isset($weeks[$key])) {
                $weeks[$key]['visit_count'] += (int)($row['visit_count'] ?? 0);
            }
        } catch (Throwable $e) {}
    }

    return array_values($weeks);
}

function tracker_ch_to_local(?string $utcDt, string $tzName): ?string {
    if (!$utcDt) return null;
    try {
        $dt = new DateTime($utcDt, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($tzName));
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

$clanParam = tracker_ch_get_param('clan', 32);
if ($clanParam === '') $clanParam = trim((string)(getenv('TRACKER_CLAN_ID') ?: ''));

$clanId = (int)$clanParam;
if ($clanId <= 0) {
    tracker_json(['ok' => false, 'error' => 'Missing clan. Set TRACKER_CLAN_ID in .env or pass ?clan=.'], 400);
}

$pdo = tracker_pdo();

$stmt = $pdo->prepare("
SELECT id, name, timezone, reset_weekday, reset_time, is_enabled
FROM clans
WHERE id = :id AND is_enabled = 1 AND inactive_at IS NULL
LIMIT 1
");
$stmt->execute([':id' => $clanId]);
$clan = $stmt->fetch();
if (!$clan) tracker_json(['ok' => false, 'error' => 'Clan not found'], 404);

$tzName = (string)($clan['timezone'] ?? 'UTC');
try { new DateTimeZone($tzName); }
catch (Throwable $e) { $tzName = 'UTC'; }

$stmt = $pdo->prepare("
SELECT
  m.id,
  m.rsn,
  m.rank_name,
  m.is_private,
  COALESCE(c.cap_count, 0) AS cap_count,
  c.last_capped_at_utc,
  COALESCE(v.visit_count, 0) AS visit_count,
  v.last_visited_at_utc
FROM members m
LEFT JOIN (
  SELECT clan_id, member_id, COUNT(*) AS cap_count, MAX(capped_at_utc) AS last_capped_at_utc
  FROM member_caps
  WHERE clan_id = :cid_caps
  GROUP BY clan_id, member_id
) c ON c.clan_id = m.clan_id AND c.member_id = m.id
LEFT JOIN (
  SELECT clan_id, member_id, COUNT(*) AS visit_count, MAX(visited_at_utc) AS last_visited_at_utc
  FROM member_citadel_visits
  WHERE clan_id = :cid_visits
  GROUP BY clan_id, member_id
) v ON v.clan_id = m.clan_id AND v.member_id = m.id
WHERE m.clan_id = :cid
  AND m.is_active = 1
  AND LOWER(TRIM(COALESCE(m.rank_name, ''))) <> 'guest'
ORDER BY m.rsn ASC
");
$stmt->execute([
    ':cid' => $clanId,
    ':cid_caps' => $clanId,
    ':cid_visits' => $clanId,
]);

$members = $stmt->fetchAll();
usort($members, 'tracker_ch_compare_members_by_rank_desc');

$totalCaps = 0;
$totalVisits = 0;
$rankGroups = [];
$outMembers = [];

foreach ($members as $m) {
    $rank = trim((string)($m['rank_name'] ?? ''));
    if ($rank === '') $rank = 'Unranked';

    $caps = (int)($m['cap_count'] ?? 0);
    $visits = (int)($m['visit_count'] ?? 0);
    $totalCaps += $caps;
    $totalVisits += $visits;

    $row = [
        'id' => (int)$m['id'],
        'rsn' => (string)$m['rsn'],
        'rank_name' => $rank,
        'rank_icon' => tracker_ch_rank_icon($rank),
        'is_private' => ((int)($m['is_private'] ?? 0) === 1),
        'cap_count' => $caps,
        'visit_count' => $visits,
        'last_capped_at_utc' => $m['last_capped_at_utc'] ?? null,
        'last_capped_at_local' => tracker_ch_to_local($m['last_capped_at_utc'] ?? null, $tzName),
        'last_visited_at_utc' => $m['last_visited_at_utc'] ?? null,
        'last_visited_at_local' => tracker_ch_to_local($m['last_visited_at_utc'] ?? null, $tzName),
    ];

    $outMembers[] = $row;

    if (!isset($rankGroups[$rank])) {
        $rankGroups[$rank] = [
            'rank_name' => $rank,
            'rank_icon' => tracker_ch_rank_icon($rank),
            'member_count' => 0,
            'cap_count' => 0,
            'visit_count' => 0,
            'members' => [],
        ];
    }

    $rankGroups[$rank]['member_count']++;
    $rankGroups[$rank]['cap_count'] += $caps;
    $rankGroups[$rank]['visit_count'] += $visits;
    $rankGroups[$rank]['members'][] = $row;
}

$groups = array_values($rankGroups);
usort($groups, function(array $a, array $b): int {
    return tracker_ch_compare_ranks_desc((string)($a['rank_name'] ?? ''), (string)($b['rank_name'] ?? ''));
});

$citadelPerWeek52 = tracker_ch_citadel_per_week_52($pdo, $clanId, $clan);

tracker_json([
    'ok' => true,
    'clan' => [
        'id' => (int)$clan['id'],
        'name' => (string)$clan['name'],
        'timezone' => $tzName,
    ],
    'stats' => [
        'active_members' => count($outMembers),
        'total_caps' => $totalCaps,
        'total_visits' => $totalVisits,
    ],
    'citadel_per_week_1y' => $citadelPerWeek52,
    // Legacy response keys retained for cached clients; all series use calendar weeks.
    'citadel_per_cap_week_1y' => $citadelPerWeek52,
    'caps_per_cap_week_1y' => $citadelPerWeek52,
    'members' => $outMembers,
    'rank_groups' => $groups,
    'generated_at_utc' => gmdate('Y-m-d H:i:s'),
]);
