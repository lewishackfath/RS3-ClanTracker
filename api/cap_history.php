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
    ];

    return isset($fileMap[$key]) ? 'assets/ranks/' . $fileMap[$key] . '_Clan_Rank.png' : '';
}


function tracker_ch_caps_per_day_90(PDO $pdo, int $clanId, string $tzName): array {
    try {
        $tz = new DateTimeZone($tzName);
    } catch (Throwable $e) {
        $tz = new DateTimeZone('UTC');
    }

    $utc = new DateTimeZone('UTC');
    $todayLocal = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0, 0);
    $startLocal = $todayLocal->modify('-89 days');
    $endLocalExclusive = $todayLocal->modify('+1 day');

    $days = [];
    for ($i = 0; $i < 90; $i++) {
        $day = $startLocal->modify('+' . $i . ' days');
        $key = $day->format('Y-m-d');
        $days[$key] = [
            'date' => $key,
            'label' => $day->format('j M'),
            'count' => 0,
        ];
    }

    $startUtc = $startLocal->setTimezone($utc)->format('Y-m-d H:i:s');
    $endUtc = $endLocalExclusive->setTimezone($utc)->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("\n        SELECT capped_at_utc\n        FROM member_caps\n        WHERE clan_id = :cid\n          AND capped_at_utc >= :start_utc\n          AND capped_at_utc < :end_utc\n        ORDER BY capped_at_utc ASC\n    ");
    $stmt->execute([
        ':cid' => $clanId,
        ':start_utc' => $startUtc,
        ':end_utc' => $endUtc,
    ]);

    while ($row = $stmt->fetch()) {
        $raw = (string)($row['capped_at_utc'] ?? '');
        if ($raw === '') continue;
        try {
            $dt = new DateTimeImmutable($raw, $utc);
            $key = $dt->setTimezone($tz)->format('Y-m-d');
            if (isset($days[$key])) $days[$key]['count']++;
        } catch (Throwable $e) {}
    }

    return array_values($days);
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

$capsPerDay90 = tracker_ch_caps_per_day_90($pdo, $clanId, $tzName);

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
    'caps_per_day_90d' => $capsPerDay90,
    'members' => $outMembers,
    'rank_groups' => $groups,
    'generated_at_utc' => gmdate('Y-m-d H:i:s'),
]);
