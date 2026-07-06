<?php
declare(strict_types=1);

require_once __DIR__ . '/_db.php';

function tracker_cc_get_param(string $key, int $maxLen = 64): string {
    $v = trim((string)($_GET[$key] ?? ''));
    if ($v === '') return '';
    if (mb_strlen($v) > $maxLen) $v = mb_substr($v, 0, $maxLen);
    return $v;
}

function tracker_cc_normalise_rank(?string $rank): string {
    $rank = strtolower(trim((string)$rank));
    $rank = preg_replace('/[^a-z0-9]+/', ' ', $rank) ?: '';
    $rank = preg_replace('/\s+/', ' ', $rank) ?: '';
    return trim($rank);
}

function tracker_cc_rank_sort_index(?string $rank): int {
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

    $key = tracker_cc_normalise_rank($rank);
    return array_key_exists($key, $map) ? (int)$map[$key] : -1;
}

function tracker_cc_compare_ranks_desc(string $a, string $b): int {
    $av = tracker_cc_rank_sort_index($a);
    $bv = tracker_cc_rank_sort_index($b);
    if ($av !== $bv) return $bv <=> $av;
    return strcasecmp($a, $b);
}

function tracker_cc_compare_members_by_rank_desc(array $a, array $b): int {
    $rankCompare = tracker_cc_compare_ranks_desc((string)($a['rank_name'] ?? ''), (string)($b['rank_name'] ?? ''));
    if ($rankCompare !== 0) return $rankCompare;
    return strcasecmp((string)($a['rsn'] ?? ''), (string)($b['rsn'] ?? ''));
}

function tracker_cc_rank_icon(?string $rank): string {
    $key = tracker_cc_normalise_rank($rank);
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

function tracker_cc_parse_json($value): array {
    if ($value === null || $value === '') return [];
    if (is_array($value)) return $value;
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

function tracker_cc_skill_order(): array {
    return [
        'Attack','Defence','Strength','Constitution','Ranged','Prayer','Magic',
        'Cooking','Woodcutting','Fletching','Fishing','Firemaking','Crafting','Smithing','Mining',
        'Herblore','Agility','Thieving','Slayer','Farming','Runecrafting','Hunter','Construction',
        'Summoning','Dungeoneering','Divination','Invention','Archaeology','Necromancy',
    ];
}

function tracker_cc_skill_key(string $name): string {
    $s = mb_strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9]+/u', '', $s) ?? $s;
    return $s;
}

function tracker_cc_extract_skill_stats(array $skills): array {
    $out = [];
    foreach ($skills as $skill => $row) {
        if (!is_array($row)) continue;
        $lvl = $row['level'] ?? null;
        $xp = $row['xp'] ?? null;
        $out[(string)$skill] = [
            'level' => is_numeric($lvl) ? (int)$lvl : null,
            'xp' => is_numeric($xp) ? (int)$xp : null,
        ];
    }
    return $out;
}

function tracker_cc_virtual_level_from_xp(int $xp, string $skill): int {
    // Lightweight server-side fallback. The UI can still use the exact frontend helper,
    // but this keeps comparison rows useful without requiring client-side recalculation.
    if ($xp >= 200000000) return 126;
    if ($xp >= 104273167) return 120;
    if ($xp >= 13034431) return 99;
    return 0;
}

function tracker_cc_skill_summary(?string $skillsJson, ?int $totalXp): array {
    $raw = tracker_cc_parse_json($skillsJson);
    $stats = tracker_cc_extract_skill_stats($raw);

    $totalLevel = null;
    $xpTotal = is_numeric($totalXp) ? (int)$totalXp : null;

    if (isset($raw['total']) && is_array($raw['total'])) {
        if (isset($raw['total']['level']) && is_numeric($raw['total']['level'])) $totalLevel = (int)$raw['total']['level'];
        if ($xpTotal === null && isset($raw['total']['xp']) && is_numeric($raw['total']['xp'])) $xpTotal = (int)$raw['total']['xp'];
    }

    $sumLevel = 0;
    $sumXp = 0;
    $skillRows = [];
    foreach (tracker_cc_skill_order() as $name) {
        $row = $stats[$name] ?? ['level' => null, 'xp' => null];
        $level = $row['level'];
        $xp = $row['xp'];
        if (is_numeric($level)) $sumLevel += (int)$level;
        if (is_numeric($xp)) $sumXp += (int)$xp;

        $displayLevel = is_numeric($level) ? (int)$level : null;
        if (is_numeric($xp)) {
            $virtual = tracker_cc_virtual_level_from_xp((int)$xp, $name);
            if ($virtual > 0) $displayLevel = max((int)($displayLevel ?? 0), $virtual);
        }

        $skillRows[] = [
            'skill' => $name,
            'skill_key' => tracker_cc_skill_key($name),
            'level' => $level,
            'display_level' => $displayLevel,
            'xp' => $xp,
        ];
    }

    if ($totalLevel === null) $totalLevel = $sumLevel > 0 ? $sumLevel : null;
    if ($xpTotal === null) $xpTotal = $sumXp > 0 ? $sumXp : null;

    $highest = null;
    $lowest = null;
    foreach ($skillRows as $row) {
        if (!is_numeric($row['display_level'])) continue;
        if ($highest === null
            || (int)$row['display_level'] > (int)$highest['display_level']
            || ((int)$row['display_level'] === (int)$highest['display_level'] && (int)($row['xp'] ?? 0) > (int)($highest['xp'] ?? 0))) {
            $highest = $row;
        }
        if ($lowest === null
            || (int)$row['display_level'] < (int)$lowest['display_level']
            || ((int)$row['display_level'] === (int)$lowest['display_level'] && (int)($row['xp'] ?? 0) < (int)($lowest['xp'] ?? 0))) {
            $lowest = $row;
        }
    }

    return [
        'has_data' => !empty($stats),
        'total_level' => $totalLevel,
        'total_xp' => $xpTotal,
        'highest_skill' => $highest,
        'lowest_skill' => $lowest,
    ];
}

function tracker_cc_json_path(array $data, array $path) {
    $cur = $data;
    foreach ($path as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) return null;
        $cur = $cur[$part];
    }
    return $cur;
}

function tracker_cc_score_value($row): ?int {
    if (!is_array($row)) return null;
    $v = $row['score'] ?? null;
    return is_numeric($v) ? (int)$v : null;
}

$clanParam = tracker_cc_get_param('clan', 32);
if ($clanParam === '') $clanParam = trim((string)(getenv('TRACKER_CLAN_ID') ?: ''));
$clanId = (int)$clanParam;
if ($clanId <= 0) {
    tracker_json(['ok' => false, 'error' => 'Missing clan. Set TRACKER_CLAN_ID in .env or pass ?clan=.'], 400);
}

$pdo = tracker_pdo();

$stmt = $pdo->prepare("SELECT id, name, timezone, is_enabled FROM clans WHERE id = :id AND is_enabled = 1 AND inactive_at IS NULL LIMIT 1");
$stmt->execute([':id' => $clanId]);
$clan = $stmt->fetch();
if (!$clan) tracker_json(['ok' => false, 'error' => 'Clan not found'], 404);

$stmt = $pdo->prepare("
SELECT
  m.id,
  m.rsn,
  m.rank_name,
  m.is_private,
  m.last_sync,
  xs.captured_at_utc AS snapshot_at_utc,
  xs.total_xp,
  xs.skills_json,
  h.json_data AS hiscores_json,
  h.updated_at AS hiscores_updated_at,
  q.json_data AS quests_json,
  q.updated_at AS quests_updated_at
FROM members m
LEFT JOIN member_xp_snapshots xs
  ON xs.member_id = m.id
 AND xs.captured_at_utc = (
    SELECT MAX(xs2.captured_at_utc)
    FROM member_xp_snapshots xs2
    WHERE xs2.member_id = m.id
 )
LEFT JOIN member_hiscores_lite h
  ON h.member_id = m.id
 AND h.member_clan_id = m.clan_id
LEFT JOIN member_rm_quests q
  ON q.member_id = m.id
 AND q.member_clan_id = m.clan_id
WHERE m.clan_id = :cid
  AND m.is_active = 1
ORDER BY m.rsn ASC
");
$stmt->execute([':cid' => $clanId]);
$rows = $stmt->fetchAll();

$members = [];
foreach ($rows as $row) {
    $rank = trim((string)($row['rank_name'] ?? ''));
    if ($rank === '') $rank = 'Unranked';

    $skillSummary = tracker_cc_skill_summary($row['skills_json'] ?? null, isset($row['total_xp']) ? (int)$row['total_xp'] : null);

    $hiscores = tracker_cc_parse_json($row['hiscores_json'] ?? null);
    $quests = tracker_cc_parse_json($row['quests_json'] ?? null);

    $runescore = tracker_cc_score_value(tracker_cc_json_path($hiscores, ['summary', 'runescore']));
    $cluesTotal = tracker_cc_json_path($hiscores, ['summary', 'clues_total_from_tiers']);
    $cluesTotal = is_numeric($cluesTotal) ? (int)$cluesTotal : null;
    $questPoints = tracker_cc_json_path($quests, ['totals', 'quest_points_completed']);
    $questPoints = is_numeric($questPoints) ? (int)$questPoints : null;

    $members[] = [
        'id' => (int)$row['id'],
        'rsn' => (string)$row['rsn'],
        'rank_name' => $rank,
        'rank_icon' => tracker_cc_rank_icon($rank),
        'is_private' => ((int)($row['is_private'] ?? 0) === 1),
        'total_level' => $skillSummary['total_level'],
        'total_xp' => $skillSummary['total_xp'],
        'highest_skill' => $skillSummary['highest_skill'],
        'lowest_skill' => $skillSummary['lowest_skill'],
        'runescore' => $runescore,
        'quest_points' => $questPoints,
        'clue_total' => $cluesTotal,
        'snapshot_at_utc' => $row['snapshot_at_utc'] ?? null,
        'hiscores_updated_at_utc' => $row['hiscores_updated_at'] ?? null,
        'quests_updated_at_utc' => $row['quests_updated_at'] ?? null,
    ];
}

usort($members, 'tracker_cc_compare_members_by_rank_desc');

$ranksSet = [];
foreach ($members as $m) $ranksSet[(string)$m['rank_name']] = true;
$ranks = array_keys($ranksSet);
usort($ranks, 'tracker_cc_compare_ranks_desc');

tracker_json([
    'ok' => true,
    'clan' => [
        'id' => (int)$clan['id'],
        'name' => (string)$clan['name'],
        'timezone' => (string)($clan['timezone'] ?? 'UTC'),
    ],
    'stats' => [
        'active_members' => count($members),
        'private_profiles' => count(array_filter($members, static fn($m) => !empty($m['is_private']))),
    ],
    'ranks' => $ranks,
    'members' => $members,
    'generated_at_utc' => gmdate('Y-m-d H:i:s'),
]);
