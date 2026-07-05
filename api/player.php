<?php
declare(strict_types=1);

require_once __DIR__ . '/_db.php';

/**
 * api/player.php (NEW SCHEMA)
 *
 * Uses tables:
 * - clans: id, name, timezone, reset_weekday, reset_time, is_enabled, inactive_at
 * - members: id, clan_id, rsn, rsn_normalised, rank_name, is_active, updated_at
 * - member_caps: clan_id, member_id, cap_week_start_utc, capped_at_utc, ...
 * - member_citadel_visits: clan_id, member_id, cap_week_start_utc, visited_at_utc, ...
 * - member_activities: activity_text/activity_details/... (this is the activity log)
 * - member_xp_snapshots: member_id, total_xp, skills_json, snapshot_hash, captured_at_utc
 * - member_poll_state: last_poll_at_utc
 */

function tracker_get_param(string $key, int $maxLen = 64): string {
    $v = (string)($_GET[$key] ?? '');
    $v = trim($v);
    if ($v === '') return '';
    if (mb_strlen($v) > $maxLen) $v = mb_substr($v, 0, $maxLen);
    return $v;
}

/**
 * Live RuneMetrics quest completion fetcher (no DB storage).
 * Endpoint: https://apps.runescape.com/runemetrics/quests?user=<RSN>
 */
function get_quest_data(string $rsn): array {
    $rsn = trim($rsn);
    if ($rsn === '') {
        return ['ok' => false, 'http_code' => 0, 'rsn' => '', 'data' => null, 'error' => 'player required', 'hint' => null];
    }

    // RuneScape endpoint expects underscores instead of spaces in the RSN
    $apiName = preg_replace('/\s+/', '_', $rsn) ?? $rsn;
    $url = 'https://apps.runescape.com/runemetrics/quests?user=' . rawurlencode($apiName);

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'http_code' => 0, 'rsn' => $rsn, 'data' => null, 'error' => 'curl_init failed', 'hint' => null];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json, text/plain, */*',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ],
        CURLOPT_USERAGENT => '24K-Tracker/1.0 (+https://tracker.24krs.com.au)',
    ]);

    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $raw === '') {
        return ['ok' => false, 'http_code' => $http, 'rsn' => $rsn, 'data' => null, 'error' => 'request failed', 'hint' => ($err ?: null)];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'http_code' => $http, 'rsn' => $rsn, 'data' => null, 'error' => 'invalid json', 'hint' => substr((string)$raw, 0, 240)];
    }

    if ($http !== 200) {
        return [
            'ok' => false,
            'http_code' => $http,
            'rsn' => $rsn,
            'data' => $data,
            'error' => 'runemetrics http ' . $http,
            'hint' => isset($data['error']) ? (string)$data['error'] : null,
        ];
    }

    return ['ok' => true, 'http_code' => $http, 'rsn' => $rsn, 'data' => $data, 'error' => null, 'hint' => null];
}


function tracker_week_window(array $clan): array {
    $tzName = (string)($clan['timezone'] ?? 'UTC');
    try { $tz = new DateTimeZone($tzName); }
    catch (Throwable $e) { $tzName = 'UTC'; $tz = new DateTimeZone('UTC'); }

    $now = new DateTime('now', $tz);

    $resetWeekday = (int)($clan['reset_weekday'] ?? 1); // your DB appears to use 0-6 (Sun-Sat)
    $resetTimeRaw = (string)($clan['reset_time'] ?? '00:00:00');

    $parts = array_map('intval', explode(':', $resetTimeRaw));
    $h = $parts[0] ?? 0; $m = $parts[1] ?? 0; $s = $parts[2] ?? 0;

    $startLocal = null;

    for ($i = 0; $i <= 7; $i++) {
        $cand = clone $now;
        $cand->setTime($h, $m, $s);
        if ($i > 0) $cand->modify("-{$i} days");

        $match = ((int)$cand->format('w') === $resetWeekday);
        if ($match && $cand <= $now) { $startLocal = $cand; break; }
    }

    if (!$startLocal) {
        $startLocal = clone $now;
        $startLocal->setTime($h, $m, $s);
    }

    $endLocal = clone $startLocal;
    $endLocal->modify('+7 days');

    $startUtc = clone $startLocal; $startUtc->setTimezone(new DateTimeZone('UTC'));
    $endUtc   = clone $endLocal;   $endUtc->setTimezone(new DateTimeZone('UTC'));

    return [
        'timezone' => $tzName,
        'week_start_local' => $startLocal->format('Y-m-d H:i:s'),
        'week_end_local'   => $endLocal->format('Y-m-d H:i:s'),
        'week_start_utc'   => $startUtc->format('Y-m-d H:i:s'),
        'week_end_utc'     => $endUtc->format('Y-m-d H:i:s'),
    ];
}

function tracker_period_window(string $period, array $weekWindow): array {
    $period = strtolower(trim($period));
    if ($period === '') $period = '7d';

    // Clan timezone (falls back to UTC)
    $tzName = (string)($weekWindow['timezone'] ?? 'UTC');
    try { $clanTz = new DateTimeZone($tzName); }
    catch (Throwable $e) { $tzName = 'UTC'; $clanTz = new DateTimeZone('UTC'); }

    $nowUtc = new DateTime('now', new DateTimeZone('UTC'));
    $nowLocal = new DateTime('now', $clanTz);

    if ($period === 'thisweek') {
        return [
            'period' => 'thisweek',
            'start_utc' => $weekWindow['week_start_utc'],
            'end_utc' => $weekWindow['week_end_utc'],
        ];
    }

    if ($period === 'lastweek') {
        $start = new DateTime($weekWindow['week_start_utc'], new DateTimeZone('UTC'));
        $start->modify('-7 days');
        $end = new DateTime($weekWindow['week_end_utc'], new DateTimeZone('UTC'));
        $end->modify('-7 days');
        return [
            'period' => 'lastweek',
            'start_utc' => $start->format('Y-m-d H:i:s'),
            'end_utc' => $end->format('Y-m-d H:i:s'),
        ];
    }

    // Month windows are based on the clan's local timezone
    if ($period === 'thismonth') {
        $startLocal = new DateTime($nowLocal->format('Y-m-01 00:00:00'), $clanTz);
        $startUtc = clone $startLocal; $startUtc->setTimezone(new DateTimeZone('UTC'));
        return [
            'period' => 'thismonth',
            'start_utc' => $startUtc->format('Y-m-d H:i:s'),
            'end_utc' => $nowUtc->format('Y-m-d H:i:s'),
        ];
    }

    if ($period === 'lastmonth') {
        $startThisMonthLocal = new DateTime($nowLocal->format('Y-m-01 00:00:00'), $clanTz);
        $startLastMonthLocal = clone $startThisMonthLocal;
        $startLastMonthLocal->modify('-1 month');

        $startUtc = clone $startLastMonthLocal; $startUtc->setTimezone(new DateTimeZone('UTC'));
        $endUtc = clone $startThisMonthLocal; $endUtc->setTimezone(new DateTimeZone('UTC'));
        // make end inclusive for queries using <= :endUtc
        $endUtc->modify('-1 second');

        return [
            'period' => 'lastmonth',
            'start_utc' => $startUtc->format('Y-m-d H:i:s'),
            'end_utc' => $endUtc->format('Y-m-d H:i:s'),
        ];
    }

    // All-time: let the queries find the earliest snapshot automatically
    if ($period === 'alltime') {
        return [
            'period' => 'alltime',
            'start_utc' => '1970-01-01 00:00:00',
            'end_utc' => $nowUtc->format('Y-m-d H:i:s'),
        ];
    }

    $dur = null;
    if ($period === '24h') $dur = 'PT24H';
    elseif ($period === '7d') $dur = 'P7D';
    elseif ($period === '30d') $dur = 'P30D';
    elseif ($period === '90d') $dur = 'P90D';

    if ($dur) {
        $start = clone $nowUtc;
        $start->sub(new DateInterval($dur));
        return [
            'period' => $period,
            'start_utc' => $start->format('Y-m-d H:i:s'),
            'end_utc' => $nowUtc->format('Y-m-d H:i:s'),
        ];
    }

    $start = clone $nowUtc;
    $start->sub(new DateInterval('P7D'));
    return [
        'period' => '7d',
        'start_utc' => $start->format('Y-m-d H:i:s'),
        'end_utc' => $nowUtc->format('Y-m-d H:i:s'),
    ];
}

function tracker_parse_skills_json($skillsJson): array {
    if ($skillsJson === null) return [];
    if (is_string($skillsJson)) {
        $decoded = json_decode($skillsJson, true);
        return is_array($decoded) ? $decoded : [];
    }
    return is_array($skillsJson) ? $skillsJson : [];
}

function tracker_extract_skill_stats(array $skills): array {
    $out = [];
    foreach ($skills as $skill => $row) {
        if (!is_array($row)) continue;
        $lvl = $row['level'] ?? null;
        $xp  = $row['xp'] ?? null;

        $out[(string)$skill] = [
            'level' => is_numeric($lvl) ? (int)$lvl : null,
            'xp'    => is_numeric($xp) ? (int)$xp : null,
        ];
    }
    return $out;
}

function tracker_skill_key(string $name): string {
    $s = mb_strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9]+/u', '', $s) ?? $s;
    return $s;
}

function tracker_skill_order(): array {
    return [
        'Attack','Defence','Strength','Constitution','Ranged','Prayer','Magic',
        'Cooking','Woodcutting','Fletching','Fishing','Firemaking','Crafting','Smithing','Mining',
        'Herblore','Agility','Thieving','Slayer','Farming','Runecrafting','Hunter','Construction',
        'Summoning','Dungeoneering','Divination','Invention','Archaeology','Necromancy',
    ];
}


function tracker_snapshot_total_xp(array $row): ?int {
    if (isset($row['total_xp']) && is_numeric($row['total_xp'])) return (int)$row['total_xp'];

    $rawSkills = tracker_parse_skills_json($row['skills_json'] ?? null);
    if (isset($rawSkills['total']) && is_array($rawSkills['total']) && isset($rawSkills['total']['xp']) && is_numeric($rawSkills['total']['xp'])) {
        return (int)$rawSkills['total']['xp'];
    }

    $stats = tracker_extract_skill_stats($rawSkills);
    if (!$stats) return null;

    $sum = 0;
    $seen = false;
    foreach (tracker_skill_order() as $skillName) {
        $xp = $stats[$skillName]['xp'] ?? null;
        if (!is_numeric($xp)) continue;
        $sum += (int)$xp;
        $seen = true;
    }

    return $seen ? $sum : null;
}

function tracker_short_local_label(?string $utcValue, string $timezone): string {
    if (!$utcValue) return '';
    try { $tz = new DateTimeZone($timezone); }
    catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }

    try {
        $dt = new DateTime((string)$utcValue, new DateTimeZone('UTC'));
        $dt->setTimezone($tz);
        return $dt->format('d M');
    } catch (Throwable $e) {
        return (string)$utcValue;
    }
}

function tracker_snapshot_skill_xp_map(array $row): array {
    $rawSkills = tracker_parse_skills_json($row['skills_json'] ?? null);
    $stats = tracker_extract_skill_stats($rawSkills);
    $out = [];

    foreach (tracker_skill_order() as $skillName) {
        $xp = $stats[$skillName]['xp'] ?? null;
        if (is_numeric($xp)) $out[$skillName] = (int)$xp;
    }

    return $out;
}

function tracker_skill_gain_rows_between(?array $startSnap, ?array $endSnap): array {
    if (!$startSnap || !$endSnap) return [];

    $startSkills = tracker_snapshot_skill_xp_map($startSnap);
    $endSkills = tracker_snapshot_skill_xp_map($endSnap);
    $rows = [];

    foreach (tracker_skill_order() as $skillName) {
        $startXp = $startSkills[$skillName] ?? null;
        $endXp = $endSkills[$skillName] ?? null;
        if ($startXp === null || $endXp === null) continue;
        $gain = max(0, (int)$endXp - (int)$startXp);
        $rows[] = [
            'skill' => $skillName,
            'skill_key' => tracker_skill_key($skillName),
            'gained_xp' => $gain,
        ];
    }

    usort($rows, static function(array $a, array $b): int {
        $cmp = ((int)($b['gained_xp'] ?? 0)) <=> ((int)($a['gained_xp'] ?? 0));
        if ($cmp !== 0) return $cmp;
        return strcmp((string)($a['skill'] ?? ''), (string)($b['skill'] ?? ''));
    });

    return $rows;
}

function tracker_fetch_window_snapshots(PDO $pdo, int $memberId, string $startUtc, string $endUtc, int $limit = 2000): array {
    $stmt = $pdo->prepare("\n        SELECT captured_at_utc, total_xp, skills_json\n        FROM member_xp_snapshots\n        WHERE member_id = :mid AND captured_at_utc <= :startUtc\n        ORDER BY captured_at_utc DESC\n        LIMIT 1\n    ");
    $stmt->execute([':mid' => $memberId, ':startUtc' => $startUtc]);
    $baseline = $stmt->fetch() ?: null;

    $stmt = $pdo->prepare("\n        SELECT captured_at_utc, total_xp, skills_json\n        FROM member_xp_snapshots\n        WHERE member_id = :mid\n          AND captured_at_utc > :startUtc\n          AND captured_at_utc <= :endUtc\n        ORDER BY captured_at_utc ASC\n        LIMIT " . max(1, min(5000, $limit)) . "\n    ");
    $stmt->execute([':mid' => $memberId, ':startUtc' => $startUtc, ':endUtc' => $endUtc]);
    $rows = $stmt->fetchAll() ?: [];

    if (!$baseline && $rows) $baseline = $rows[0];

    return [$baseline, $rows];
}

function tracker_build_skill_gains_for_window(PDO $pdo, int $memberId, string $startUtc, string $endUtc): array {
    [$baseline, $rows] = tracker_fetch_window_snapshots($pdo, $memberId, $startUtc, $endUtc, 2000);
    if (!$baseline || !$rows) return [];
    $endSnap = $rows[count($rows) - 1];
    return tracker_skill_gain_rows_between($baseline, $endSnap);
}

function tracker_local_date_key(string $utcValue, DateTimeZone $tz): string {
    try {
        $dt = new DateTime($utcValue, new DateTimeZone('UTC'));
        $dt->setTimezone($tz);
        return $dt->format('Y-m-d');
    } catch (Throwable $e) {
        return substr($utcValue, 0, 10);
    }
}

function tracker_local_short_day_label(string $dateKey, DateTimeZone $tz): string {
    try {
        $dt = new DateTime($dateKey . ' 12:00:00', $tz);
        return $dt->format('j M');
    } catch (Throwable $e) {
        return $dateKey;
    }
}

function tracker_build_daily_skill_xp_30d(PDO $pdo, int $memberId, string $timezone): array {
    try { $tz = new DateTimeZone($timezone); }
    catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }

    $endLocal = new DateTime('now', $tz);
    $endLocal->setTime(23, 59, 59);
    $startLocal = clone $endLocal;
    $startLocal->modify('-29 days')->setTime(0, 0, 0);

    $startUtcDt = clone $startLocal;
    $startUtcDt->setTimezone(new DateTimeZone('UTC'));
    $endUtcDt = clone $endLocal;
    $endUtcDt->setTimezone(new DateTimeZone('UTC'));

    $startUtc = $startUtcDt->format('Y-m-d H:i:s');
    $endUtc = $endUtcDt->format('Y-m-d H:i:s');

    [$baseline, $rows] = tracker_fetch_window_snapshots($pdo, $memberId, $startUtc, $endUtc, 3000);

    $days = [];
    $cursor = clone $startLocal;
    for ($i = 0; $i < 30; $i++) {
        $key = $cursor->format('Y-m-d');
        $skills = [];
        foreach (tracker_skill_order() as $skillName) $skills[$skillName] = 0;
        $days[$key] = [
            'date' => $key,
            'label' => tracker_local_short_day_label($key, $tz),
            'total_xp' => 0,
            'skills' => $skills,
        ];
        $cursor->modify('+1 day');
    }

    if (!$baseline || !$rows) return array_values($days);

    $prev = $baseline;
    foreach ($rows as $row) {
        $captured = (string)($row['captured_at_utc'] ?? '');
        if ($captured === '') continue;
        $key = tracker_local_date_key($captured, $tz);
        if (!isset($days[$key])) {
            $prev = $row;
            continue;
        }

        $gains = tracker_skill_gain_rows_between($prev, $row);
        foreach ($gains as $gainRow) {
            $skill = (string)($gainRow['skill'] ?? '');
            $gain = max(0, (int)($gainRow['gained_xp'] ?? 0));
            if ($skill === '' || $gain <= 0) continue;
            $days[$key]['skills'][$skill] = (int)($days[$key]['skills'][$skill] ?? 0) + $gain;
            $days[$key]['total_xp'] = (int)$days[$key]['total_xp'] + $gain;
        }

        $prev = $row;
    }

    return array_values($days);
}

function tracker_build_xp_stats(PDO $pdo, int $memberId, array $xpWindow, string $timezone): array {
    $startUtc = (string)($xpWindow['start_utc'] ?? '1970-01-01 00:00:00');
    $endUtc = (string)($xpWindow['end_utc'] ?? gmdate('Y-m-d H:i:s'));

    [$baseline, $rows] = tracker_fetch_window_snapshots($pdo, $memberId, $startUtc, $endUtc, 500);

    $baselineXp = $baseline ? tracker_snapshot_total_xp($baseline) : null;
    if ($baselineXp === null && $rows) {
        foreach ($rows as $row) {
            $value = tracker_snapshot_total_xp($row);
            if ($value !== null) { $baselineXp = $value; break; }
        }
    }

    $baselineSkills = $baseline ? tracker_snapshot_skill_xp_map($baseline) : [];

    $points = [];
    $seen = [];

    $addPoint = function(array $row) use (&$points, &$seen, $baselineXp, $baselineSkills, $timezone): void {
        $captured = (string)($row['captured_at_utc'] ?? '');
        if ($captured === '' || isset($seen[$captured])) return;
        $totalXp = tracker_snapshot_total_xp($row);
        if ($totalXp === null) return;

        $currentSkills = tracker_snapshot_skill_xp_map($row);
        $skillGains = [];
        foreach (tracker_skill_order() as $skillName) {
            $startXp = $baselineSkills[$skillName] ?? null;
            $endXp = $currentSkills[$skillName] ?? null;
            $skillGains[$skillName] = (is_numeric($startXp) && is_numeric($endXp))
                ? max(0, (int)$endXp - (int)$startXp)
                : 0;
        }

        $gained = $baselineXp === null ? 0 : max(0, $totalXp - $baselineXp);
        $points[] = [
            'captured_at_utc' => $captured,
            'captured_at_local' => tracker_to_local($captured, $timezone),
            'label' => tracker_short_local_label($captured, $timezone),
            'total_xp' => $totalXp,
            'gained_xp' => $gained,
            'skills' => $skillGains,
        ];
        $seen[$captured] = true;
    };

    if ($baseline) $addPoint($baseline);
    foreach ($rows as $row) $addPoint($row);

    usort($points, static function(array $a, array $b): int {
        return strcmp((string)$a['captured_at_utc'], (string)$b['captured_at_utc']);
    });

    $finalGain = null;
    if ($points) {
        $last = $points[count($points) - 1];
        $finalGain = isset($last['gained_xp']) ? (int)$last['gained_xp'] : null;
    }

    $latestEndSnap = $rows ? $rows[count($rows) - 1] : $baseline;
    $periodSkillGains = tracker_skill_gain_rows_between($baseline, $latestEndSnap);

    $nowUtc = new DateTime('now', new DateTimeZone('UTC'));
    $sevenStart = clone $nowUtc;
    $sevenStart->sub(new DateInterval('P7D'));
    $last7dSkillGains = tracker_build_skill_gains_for_window(
        $pdo,
        $memberId,
        $sevenStart->format('Y-m-d H:i:s'),
        $nowUtc->format('Y-m-d H:i:s')
    );

    return [
        'has_data' => count($points) >= 2,
        'period' => (string)($xpWindow['period'] ?? ''),
        'start_utc' => $startUtc,
        'end_utc' => $endUtc,
        'snapshot_count' => count($points),
        'gained_total_xp' => $finalGain,
        'points' => $points,
        'skill_gains' => $periodSkillGains,
        'last_7d_skill_gains' => $last7dSkillGains,
        'daily_skill_xp_30d' => tracker_build_daily_skill_xp_30d($pdo, $memberId, $timezone),
    ];
}


function tracker_drop_item_cleanup_rules(): array {
    static $rules = null;
    if ($rules !== null) return $rules;

    $rules = [
        'strip_prefixes' => ['pair of'],
        'strip_suffixes' => [],
        'replacements' => [],
    ];

    $path = __DIR__ . '/../assets/activity/item_name_cleanup.json';
    if (!is_file($path)) return $rules;

    $json = json_decode((string)@file_get_contents($path), true);
    if (!is_array($json)) return $rules;

    foreach (['strip_prefixes', 'strip_suffixes'] as $key) {
        if (!isset($json[$key]) || !is_array($json[$key])) continue;
        foreach ($json[$key] as $value) {
            $text = trim((string)$value);
            if ($text !== '' && !in_array($text, $rules[$key], true)) {
                $rules[$key][] = $text;
            }
        }
    }

    if (isset($json['replacements']) && is_array($json['replacements'])) {
        foreach ($json['replacements'] as $rule) {
            if (!is_array($rule) || empty($rule['from'])) continue;
            $rules['replacements'][] = [
                'from' => (string)$rule['from'],
                'to' => (string)($rule['to'] ?? ''),
                'flags' => (string)($rule['flags'] ?? 'i'),
            ];
        }
    }

    return $rules;
}

function tracker_clean_drop_item_name(?string $value): ?string {
    $s = trim((string)($value ?? ''));
    if ($s === '') return null;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    $s = trim($s, "\"'“”‘’. ");

    $rules = tracker_drop_item_cleanup_rules();

    foreach (($rules['replacements'] ?? []) as $rule) {
        $from = (string)($rule['from'] ?? '');
        if ($from === '') continue;
        $flags = preg_replace('/[^imsxuADSUXJ]/', '', (string)($rule['flags'] ?? 'i')) ?: 'i';
        $pattern = '~' . str_replace('~', '\\~', $from) . '~' . $flags;
        $replaced = @preg_replace($pattern, (string)($rule['to'] ?? ''), $s);
        if (is_string($replaced)) $s = $replaced;
    }

    foreach (($rules['strip_prefixes'] ?? []) as $prefix) {
        $prefix = trim((string)$prefix);
        if ($prefix === '') continue;
        $s = preg_replace('/^' . preg_quote($prefix, '/') . '\s+/i', '', $s) ?? $s;
    }

    foreach (($rules['strip_suffixes'] ?? []) as $suffix) {
        $suffix = trim((string)$suffix);
        if ($suffix === '') continue;
        $s = preg_replace('/\s+' . preg_quote($suffix, '/') . '$/i', '', $s) ?? $s;
    }

    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    $s = trim($s, "\"'“”‘’. ");
    return $s !== '' ? $s : null;
}

function tracker_extract_drop_item_name(?string $activityText, ?string $activityDetails): ?string {
    $text = trim((string)($activityText ?? ''));
    if ($text !== '' && preg_match('/^I\s+found\s+an?\s+(.+?)(?:\.\s*)?$/i', $text, $m)) {
        return tracker_clean_drop_item_name($m[1] ?? null);
    }

    $details = trim((string)($activityDetails ?? ''));
    if ($details !== '' && preg_match('/\bdropped\s+an?\s+(.+?)(?:\.\s*|$)/i', $details, $m)) {
        return tracker_clean_drop_item_name($m[1] ?? null);
    }

    return null;
}

function tracker_build_drop_history(PDO $pdo, int $memberId, string $timezone): array {
    $stmt = $pdo->prepare("\n        SELECT activity_date_utc, announced_at, activity_text, activity_details\n        FROM member_activities\n        WHERE member_id = :mid\n          AND (\n            activity_text LIKE 'I found a %'\n            OR activity_text LIKE 'I found an %'\n            OR activity_details LIKE '%dropped a %'\n            OR activity_details LIKE '%dropped an %'\n          )\n        ORDER BY COALESCE(activity_date_utc, announced_at) DESC\n        LIMIT 10000\n    ");
    $stmt->execute([':mid' => $memberId]);
    $rows = $stmt->fetchAll() ?: [];

    $items = [];
    foreach ($rows as $row) {
        $itemName = tracker_extract_drop_item_name($row['activity_text'] ?? null, $row['activity_details'] ?? null);
        if (!$itemName) continue;

        $key = mb_strtolower($itemName);
        $seenUtc = (string)(($row['activity_date_utc'] ?? null) ?: ($row['announced_at'] ?? ''));
        if (!isset($items[$key])) {
            $items[$key] = [
                'item_name' => $itemName,
                'count' => 0,
                'last_seen_utc' => null,
                'last_seen_local' => null,
            ];
        }

        $items[$key]['count']++;
        if ($seenUtc !== '') {
            $current = $items[$key]['last_seen_utc'];
            if ($current === null || strcmp($seenUtc, (string)$current) > 0) {
                $items[$key]['last_seen_utc'] = $seenUtc;
                $items[$key]['last_seen_local'] = tracker_to_local($seenUtc, $timezone);
            }
        }
    }

    $out = array_values($items);
    usort($out, static function(array $a, array $b): int {
        $diff = ((int)($b['count'] ?? 0)) <=> ((int)($a['count'] ?? 0));
        if ($diff !== 0) return $diff;
        return strcasecmp((string)($a['item_name'] ?? ''), (string)($b['item_name'] ?? ''));
    });

    return [
        'total_detected' => array_sum(array_map(static fn(array $r): int => (int)($r['count'] ?? 0), $out)),
        'unique_items' => count($out),
        'items' => $out,
    ];
}

function tracker_dtmax(array $vals): ?string {
    $best = null;
    $bestTs = null;
    foreach ($vals as $v) {
        if (!$v) continue;
        $ts = strtotime((string)$v);
        if ($ts === false) continue;
        if ($bestTs === null || $ts > $bestTs) {
            $bestTs = $ts;
            $best = (string)$v;
        }
    }
    return $best;
}

function tracker_to_local(?string $utcDt, string $tzName): ?string {
    if (!$utcDt) return null;
    try {
        $dt = new DateTime($utcDt, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($tzName));
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}


function tracker_cache_ttl_seconds(string $envKey, int $defaultSeconds): int {
    $raw = getenv($envKey);
    if ($raw === false || trim((string)$raw) === '') return $defaultSeconds;
    $n = (int)$raw;
    return $n > 0 ? $n : $defaultSeconds;
}

function tracker_utc_now_string(): string {
    return gmdate('Y-m-d H:i:s');
}

function tracker_is_cache_fresh(?string $updatedAtUtc, int $ttlSeconds): bool {
    if (!$updatedAtUtc) return false;
    $ts = strtotime($updatedAtUtc . ' UTC');
    if ($ts === false || $ts <= 0) return false;
    return (time() - $ts) < $ttlSeconds;
}

function tracker_cache_table_name(string $cacheName): string {
    $allowed = [
        'hiscores_lite' => 'member_hiscores_lite',
        'rm_quests' => 'member_rm_quests',
    ];
    if (!isset($allowed[$cacheName])) {
        throw new InvalidArgumentException('Unknown cache table');
    }
    return $allowed[$cacheName];
}

function tracker_read_json_cache(PDO $pdo, string $cacheName, int $memberId, int $clanId): ?array {
    $table = tracker_cache_table_name($cacheName);
    $stmt = $pdo->prepare("\n        SELECT json_data, updated_at\n        FROM {$table}\n        WHERE member_id = :member_id\n          AND member_clan_id = :member_clan_id\n        LIMIT 1\n    ");
    $stmt->execute([
        ':member_id' => $memberId,
        ':member_clan_id' => $clanId,
    ]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $decoded = json_decode((string)($row['json_data'] ?? ''), true);
    if (!is_array($decoded)) return null;

    return [
        'data' => $decoded,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function tracker_write_json_cache(PDO $pdo, string $cacheName, int $memberId, int $clanId, array $payload): void {
    $table = tracker_cache_table_name($cacheName);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode JSON cache payload');
    }

    $stmt = $pdo->prepare("\n        INSERT INTO {$table}\n            (member_id, member_clan_id, json_data, updated_at)\n        VALUES\n            (:member_id, :member_clan_id, :json_data, UTC_TIMESTAMP())\n        ON DUPLICATE KEY UPDATE\n            json_data = VALUES(json_data),\n            updated_at = VALUES(updated_at)\n    ");
    $stmt->execute([
        ':member_id' => $memberId,
        ':member_clan_id' => $clanId,
        ':json_data' => $json,
    ]);
}

/**
 * Fetch and cache RuneMetrics quests for the player page.
 * The returned payload is already slimmed for the frontend, but the latest
 * source response is still represented in the summary/error fields.
 */
function tracker_get_cached_quests(PDO $pdo, int $memberId, int $clanId, string $rsn): array {
    $ttl = tracker_cache_ttl_seconds('TRACKER_RM_QUESTS_TTL_SECONDS', 6 * 60 * 60);
    $cached = tracker_read_json_cache($pdo, 'rm_quests', $memberId, $clanId);

    if ($cached && tracker_is_cache_fresh($cached['updated_at'] ?? null, $ttl)) {
        $payload = $cached['data'];
        $payload['cache'] = [
            'hit' => true,
            'stale' => false,
            'updated_at_utc' => $cached['updated_at'] ?? null,
            'ttl_seconds' => $ttl,
        ];
        return $payload;
    }

    try {
        $q = get_quest_data($rsn);
        $quests = ($q['ok'] && isset($q['data']['quests']) && is_array($q['data']['quests'])) ? $q['data']['quests'] : [];

        $totals = [
            'total' => 0,
            'completed' => 0,
            'started' => 0,
            'not_started' => 0,
            'quest_points_completed' => 0,
        ];

        $questsSlim = [];
        foreach ($quests as $quest) {
            if (!is_array($quest)) continue;

            $title = (string)($quest['title'] ?? '');
            $statusRaw = (string)($quest['status'] ?? '');
            $status = strtoupper($statusRaw);
            $qp = (int)($quest['questPoints'] ?? 0);

            $totals['total']++;

            if ($status === 'COMPLETED') {
                $totals['completed']++;
                $totals['quest_points_completed'] += $qp;
            } elseif ($status === 'STARTED' || $status === 'IN_PROGRESS') {
                $totals['started']++;
            } else {
                $totals['not_started']++;
            }

            $questsSlim[] = [
                'title' => $title,
                'status' => $statusRaw,
                'difficulty' => $quest['difficulty'] ?? null,
                'members' => isset($quest['members']) ? (bool)$quest['members'] : null,
                'questPoints' => $quest['questPoints'] ?? null,
            ];
        }

        $payload = [
            'ok' => (bool)$q['ok'],
            'http_code' => (int)($q['http_code'] ?? 0),
            'error' => $q['error'] ?? null,
            'hint' => $q['hint'] ?? null,
            'loggedIn' => $q['data']['loggedIn'] ?? null,
            'rsn' => $rsn,
            'source' => 'runemetrics_quests',
            'fetched_at_utc' => tracker_utc_now_string(),
            'totals' => $totals,
            'quests' => $questsSlim,
        ];

        // Store successful data, and also store structured API errors so the
        // player page does not repeatedly hammer a failing endpoint.
        tracker_write_json_cache($pdo, 'rm_quests', $memberId, $clanId, $payload);
        $payload['cache'] = [
            'hit' => false,
            'stale' => false,
            'updated_at_utc' => tracker_utc_now_string(),
            'ttl_seconds' => $ttl,
        ];
        return $payload;
    } catch (Throwable $e) {
        if ($cached) {
            $payload = $cached['data'];
            $payload['cache'] = [
                'hit' => true,
                'stale' => true,
                'updated_at_utc' => $cached['updated_at'] ?? null,
                'ttl_seconds' => $ttl,
                'refresh_error' => $e->getMessage(),
            ];
            return $payload;
        }

        return [
            'ok' => false,
            'http_code' => 0,
            'error' => 'Quest fetch failed',
            'hint' => $e->getMessage(),
            'loggedIn' => null,
            'rsn' => $rsn,
            'source' => 'runemetrics_quests',
            'fetched_at_utc' => tracker_utc_now_string(),
            'totals' => null,
            'quests' => [],
            'cache' => [
                'hit' => false,
                'stale' => false,
                'updated_at_utc' => null,
                'ttl_seconds' => $ttl,
            ],
        ];
    }
}

function tracker_hiscore_misc_definitions(): array {
    return [
        30 => ['key' => 'bounty_hunter', 'label' => 'Bounty Hunter', 'category' => 'legacy'],
        31 => ['key' => 'bounty_hunter_rogues', 'label' => 'Bounty Hunter Rogues', 'category' => 'legacy'],
        32 => ['key' => 'dominion_tower', 'label' => 'Dominion Tower', 'category' => 'legacy'],
        33 => ['key' => 'the_crucible', 'label' => 'The Crucible', 'category' => 'legacy'],
        34 => ['key' => 'castle_wars_games', 'label' => 'Castle Wars Games', 'category' => 'legacy'],
        35 => ['key' => 'barbarian_assault_attackers', 'label' => 'Barbarian Assault Attackers', 'category' => 'legacy'],
        36 => ['key' => 'barbarian_assault_defenders', 'label' => 'Barbarian Assault Defenders', 'category' => 'legacy'],
        37 => ['key' => 'barbarian_assault_collectors', 'label' => 'Barbarian Assault Collectors', 'category' => 'legacy'],
        38 => ['key' => 'barbarian_assault_healers', 'label' => 'Barbarian Assault Healers', 'category' => 'legacy'],
        39 => ['key' => 'duel_tournament', 'label' => 'Duel Tournament', 'category' => 'legacy'],
        40 => ['key' => 'mobilising_armies', 'label' => 'Mobilising Armies', 'category' => 'legacy'],
        41 => ['key' => 'conquest', 'label' => 'Conquest', 'category' => 'legacy'],
        42 => ['key' => 'fist_of_guthix', 'label' => 'Fist of Guthix', 'category' => 'legacy'],
        43 => ['key' => 'gielinor_games_athletics', 'label' => 'Gielinor Games Athletics', 'category' => 'legacy'],
        44 => ['key' => 'gielinor_games_resource_race', 'label' => 'Gielinor Games Resource Race', 'category' => 'legacy'],
        45 => ['key' => 'world_event_2_armadyl_lifetime', 'label' => 'WE2 Armadyl Lifetime Contribution', 'category' => 'legacy'],
        46 => ['key' => 'world_event_2_bandos_lifetime', 'label' => 'WE2 Bandos Lifetime Contribution', 'category' => 'legacy'],
        47 => ['key' => 'world_event_2_armadyl_pvp', 'label' => 'WE2 Armadyl PvP Kills', 'category' => 'legacy'],
        48 => ['key' => 'world_event_2_bandos_pvp', 'label' => 'WE2 Bandos PvP Kills', 'category' => 'legacy'],
        49 => ['key' => 'heist_guard_level', 'label' => 'Heist Guard Level', 'category' => 'legacy'],
        50 => ['key' => 'heist_robber_level', 'label' => 'Heist Robber Level', 'category' => 'legacy'],
        51 => ['key' => 'cabbage_facepunch_bonanza', 'label' => 'Cabbage Facepunch Bonanza', 'category' => 'legacy'],
        52 => ['key' => 'april_fools_2015_cow_tipping', 'label' => 'AF15 Cow Tipping', 'category' => 'legacy'],
        53 => ['key' => 'april_fools_2015_rats', 'label' => 'AF15 Rats Killed', 'category' => 'legacy'],
        54 => ['key' => 'runescore', 'label' => 'RuneScore', 'category' => 'runescore'],
        55 => ['key' => 'clue_easy', 'label' => 'Easy Clues', 'category' => 'clues'],
        56 => ['key' => 'clue_medium', 'label' => 'Medium Clues', 'category' => 'clues'],
        57 => ['key' => 'clue_hard', 'label' => 'Hard Clues', 'category' => 'clues'],
        58 => ['key' => 'clue_elite', 'label' => 'Elite Clues', 'category' => 'clues'],
        59 => ['key' => 'clue_master', 'label' => 'Master Clues', 'category' => 'clues'],
        60 => ['key' => 'clue_total', 'label' => 'Total Clues', 'category' => 'clues'],
    ];
}

function tracker_fetch_hiscores_lite_raw(string $rsn): array {
    $rsn = trim($rsn);
    if ($rsn === '') {
        return ['ok' => false, 'http_code' => 0, 'raw' => '', 'error' => 'player required', 'hint' => null];
    }

    $apiName = preg_replace('/\s+/', '_', $rsn) ?? $rsn;
    $url = 'https://secure.runescape.com/m=hiscore/index_lite.ws?player=' . rawurlencode($apiName);

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'http_code' => 0, 'raw' => '', 'error' => 'curl_init failed', 'hint' => null];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: text/plain, */*',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ],
        CURLOPT_USERAGENT => 'RS3-ClanTracker/1.0 (HiScores Lite cache)',
    ]);

    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $raw === '') {
        return ['ok' => false, 'http_code' => $http, 'raw' => '', 'error' => 'request failed', 'hint' => ($err ?: null)];
    }

    if ($http !== 200) {
        return ['ok' => false, 'http_code' => $http, 'raw' => (string)$raw, 'error' => 'hiscores http ' . $http, 'hint' => substr((string)$raw, 0, 240)];
    }

    return ['ok' => true, 'http_code' => $http, 'raw' => (string)$raw, 'error' => null, 'hint' => null];
}

function tracker_parse_hiscores_lite(string $raw, string $rsn): array {
    $rows = preg_split('/\r\n|\r|\n|\s+/', trim($raw));
    if (!is_array($rows)) $rows = [];

    $parsedRows = [];
    $misc = [];
    $defs = tracker_hiscore_misc_definitions();

    foreach ($rows as $idx => $line) {
        $line = trim((string)$line);
        if ($line === '') continue;

        $parts = array_map('trim', explode(',', $line));
        $rank = isset($parts[0]) && is_numeric($parts[0]) ? (int)$parts[0] : -1;

        if ($idx <= 29) {
            $level = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : 0;
            $xp = isset($parts[2]) && is_numeric($parts[2]) ? (int)$parts[2] : 0;
            $parsedRows[] = [
                'index' => $idx,
                'kind' => 'skill',
                'rank' => $rank,
                'level' => $level,
                'xp' => $xp,
            ];
            continue;
        }

        $score = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : 0;
        $def = $defs[$idx] ?? ['key' => 'misc_' . $idx, 'label' => 'Misc ' . $idx, 'category' => 'misc'];
        $row = [
            'index' => $idx,
            'kind' => 'misc',
            'key' => $def['key'],
            'label' => $def['label'],
            'category' => $def['category'],
            'rank' => $rank,
            'score' => $score,
            'ranked' => $rank > 0,
        ];
        $parsedRows[] = $row;
        $misc[$def['key']] = $row;
    }

    $clues = [
        'easy' => $misc['clue_easy'] ?? null,
        'medium' => $misc['clue_medium'] ?? null,
        'hard' => $misc['clue_hard'] ?? null,
        'elite' => $misc['clue_elite'] ?? null,
        'master' => $misc['clue_master'] ?? null,
        'total' => $misc['clue_total'] ?? null,
    ];

    $totalScore = 0;
    foreach (['easy', 'medium', 'hard', 'elite', 'master'] as $tier) {
        if (is_array($clues[$tier] ?? null)) $totalScore += (int)$clues[$tier]['score'];
    }

    return [
        'ok' => true,
        'http_code' => 200,
        'rsn' => $rsn,
        'source' => 'rs3_hiscores_lite',
        'fetched_at_utc' => tracker_utc_now_string(),
        'row_count' => count($parsedRows),
        'skills_ignored_for_ui' => true,
        'misc' => $misc,
        'summary' => [
            'dominion_tower' => $misc['dominion_tower'] ?? null,
            'runescore' => $misc['runescore'] ?? null,
            'clues' => $clues,
            'clues_total_from_tiers' => $totalScore,
        ],
        'rows' => $parsedRows,
    ];
}

function tracker_get_cached_hiscores_lite(PDO $pdo, int $memberId, int $clanId, string $rsn): array {
    $ttl = tracker_cache_ttl_seconds('TRACKER_HISCORES_LITE_TTL_SECONDS', 6 * 60 * 60);
    $cached = tracker_read_json_cache($pdo, 'hiscores_lite', $memberId, $clanId);

    if ($cached && tracker_is_cache_fresh($cached['updated_at'] ?? null, $ttl)) {
        $payload = $cached['data'];
        $payload['cache'] = [
            'hit' => true,
            'stale' => false,
            'updated_at_utc' => $cached['updated_at'] ?? null,
            'ttl_seconds' => $ttl,
        ];
        return $payload;
    }

    try {
        $resp = tracker_fetch_hiscores_lite_raw($rsn);
        if (!$resp['ok']) {
            throw new RuntimeException((string)($resp['error'] ?? 'HiScores fetch failed'));
        }

        $payload = tracker_parse_hiscores_lite((string)$resp['raw'], $rsn);
        $payload['http_code'] = (int)($resp['http_code'] ?? 200);
        tracker_write_json_cache($pdo, 'hiscores_lite', $memberId, $clanId, $payload);

        $payload['cache'] = [
            'hit' => false,
            'stale' => false,
            'updated_at_utc' => tracker_utc_now_string(),
            'ttl_seconds' => $ttl,
        ];
        return $payload;
    } catch (Throwable $e) {
        if ($cached) {
            $payload = $cached['data'];
            $payload['cache'] = [
                'hit' => true,
                'stale' => true,
                'updated_at_utc' => $cached['updated_at'] ?? null,
                'ttl_seconds' => $ttl,
                'refresh_error' => $e->getMessage(),
            ];
            return $payload;
        }

        return [
            'ok' => false,
            'http_code' => 0,
            'rsn' => $rsn,
            'source' => 'rs3_hiscores_lite',
            'fetched_at_utc' => tracker_utc_now_string(),
            'error' => 'HiScores Lite fetch failed',
            'hint' => $e->getMessage(),
            'misc' => [],
            'summary' => null,
            'rows' => [],
            'cache' => [
                'hit' => false,
                'stale' => false,
                'updated_at_utc' => null,
                'ttl_seconds' => $ttl,
            ],
        ];
    }
}

try {
    $player = tracker_get_param('player', 64);
    if ($player === '') tracker_json(['ok' => false, 'error' => 'Missing player'], 400);

    $period = tracker_get_param('period', 16);

    $activityLimitRaw = (int)(tracker_get_param('activity_limit', 8) ?: '20');
    $allowedActivityLimits = [20, 50, 100, 200];
    $activityLimit = in_array($activityLimitRaw, $allowedActivityLimits, true) ? $activityLimitRaw : 20;

    $preferredClanId = (int)(tracker_get_param('clan', 16) ?: (string)(getenv('TRACKER_CLAN_ID') ?: '0'));

    $pdo = tracker_pdo();

    $playerNorm = tracker_normalise($player);
    $playerNormNoSpaces = str_replace(' ', '', $playerNorm);
    $playerRawNoSpaces = str_replace(' ', '', $player);

    // Member lookup (NEW schema: members.clan_id -> clans.id)
    $stmt = $pdo->prepare("
        SELECT
          m.id,
          m.clan_id,
          m.rsn,
          m.rsn_normalised,
          m.rank_name,
          m.is_active,
          m.is_private,
          m.private_since_utc,
          m.updated_at,
          c.name AS clan_name,
          c.timezone,
          c.reset_weekday,
          c.reset_time
        FROM members m
        JOIN clans c ON c.id = m.clan_id
        WHERE
          c.is_enabled = 1
          AND c.inactive_at IS NULL
          AND (
            m.rsn_normalised = :rn
            OR REPLACE(m.rsn_normalised, ' ', '') = :rnns
            OR m.rsn = :raw
            OR REPLACE(m.rsn, ' ', '') = :rawns
          )
        ORDER BY
          CASE WHEN m.clan_id = :preferredClanId THEN 0 ELSE 1 END,
          m.is_active DESC,
          m.updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':rn' => $playerNorm,
        ':rnns' => $playerNormNoSpaces,
        ':raw' => $player,
        ':rawns' => $playerRawNoSpaces,
        ':preferredClanId' => $preferredClanId,
    ]);
    $member = $stmt->fetch();
    if (!$member) tracker_json(['ok' => false, 'error' => 'Player not found'], 404);

    $week = tracker_week_window($member);
    $xpWindow = tracker_period_window($period, $week);

    $clanId = (int)$member['clan_id'];
    $memberId = (int)$member['id'];

    // Week cap (NEW schema: member_caps has NO activity_text/activity_details)
    $stmt = $pdo->prepare("
        SELECT capped_at_utc, rule_id
        FROM member_caps
        WHERE clan_id = :clan AND member_id = :mid AND cap_week_start_utc = :ws
        LIMIT 1
    ");
    $stmt->execute([':clan' => $clanId, ':mid' => $memberId, ':ws' => $week['week_start_utc']]);
    $capRow = $stmt->fetch() ?: null;

    // Week visit (NEW schema: member_citadel_visits has NO activity_text/activity_details)
    $stmt = $pdo->prepare("
        SELECT visited_at_utc, rule_id
        FROM member_citadel_visits
        WHERE clan_id = :clan AND member_id = :mid AND cap_week_start_utc = :ws
        LIMIT 1
    ");
    $stmt->execute([':clan' => $clanId, ':mid' => $memberId, ':ws' => $week['week_start_utc']]);
    $visitRow = $stmt->fetch() ?: null;

    // Recent activity (NEW schema: member_activities DOES have activity_text)
    $stmt = $pdo->prepare("
        SELECT activity_date_utc, activity_text, activity_details, announced_at
        FROM member_activities
        WHERE member_id = :mid
          AND activity_text <> 'Rank-up required'
          AND activity_text <> 'Rank-up processed'
        ORDER BY COALESCE(activity_date_utc, announced_at) DESC
        LIMIT {$activityLimit};
    ");
    $stmt->execute([':mid' => $memberId]);
    $activityRows = $stmt->fetchAll() ?: [];

    $latestActivityUtc = null;
    if (!empty($activityRows)) {
        $latestActivityUtc = $activityRows[0]['activity_date_utc'] ?: ($activityRows[0]['announced_at'] ?? null);
    }

    // Latest XP snapshot (NEW schema: member_xp_snapshots by member_id)
    $stmt = $pdo->prepare("
        SELECT captured_at_utc, total_xp, skills_json
        FROM member_xp_snapshots
        WHERE member_id = :mid
        ORDER BY captured_at_utc DESC
        LIMIT 1
    ");
    $stmt->execute([':mid' => $memberId]);
    $latestSnap = $stmt->fetch() ?: null;
    $latestSnapUtc = $latestSnap['captured_at_utc'] ?? null;

    // Poll state (NEW schema: last_poll_at_utc)
    $stmt = $pdo->prepare("
        SELECT last_poll_at_utc
        FROM member_poll_state
        WHERE member_id = :mid
        LIMIT 1
    ");
    $stmt->execute([':mid' => $memberId]);
    $lastPolledUtc = $stmt->fetchColumn();
    if ($lastPolledUtc === false) $lastPolledUtc = null;

    // Period snapshots for XP gained (member_xp_snapshots)
    $stmt = $pdo->prepare("
        SELECT captured_at_utc, total_xp, skills_json
        FROM member_xp_snapshots
        WHERE member_id = :mid AND captured_at_utc <= :endUtc
        ORDER BY captured_at_utc DESC
        LIMIT 1
    ");
    $stmt->execute([':mid' => $memberId, ':endUtc' => $xpWindow['end_utc']]);
    $endSnap = $stmt->fetch() ?: null;

    $stmt = $pdo->prepare("
        SELECT captured_at_utc, total_xp, skills_json
        FROM member_xp_snapshots
        WHERE member_id = :mid AND captured_at_utc >= :startUtc
        ORDER BY captured_at_utc ASC
        LIMIT 1
    ");
    $stmt->execute([':mid' => $memberId, ':startUtc' => $xpWindow['start_utc']]);
    $startSnap = $stmt->fetch() ?: null;

    $xp = [
        'period' => $xpWindow['period'],
        'start_utc' => $xpWindow['start_utc'],
        'end_utc' => $xpWindow['end_utc'],
        'has_data' => false,
        'gained_total_xp' => null,
        'start_total_xp' => null,
        'end_total_xp' => null,
        'top_skills' => [],
        'skill_gains' => [],
    ];

    if ($startSnap && $endSnap) {
        $startTotal = (int)$startSnap['total_xp'];
        $endTotal = (int)$endSnap['total_xp'];
        $gainTotal = $endTotal - $startTotal;
        if ($gainTotal < 0) $gainTotal = 0;

        $skillGains = tracker_skill_gain_rows_between($startSnap, $endSnap);
        $top = $skillGains;

        $xp = [
            'period' => $xpWindow['period'],
            'start_utc' => $xpWindow['start_utc'],
            'end_utc' => $xpWindow['end_utc'],
            'has_data' => true,
            'gained_total_xp' => $gainTotal,
            'start_total_xp' => $startTotal,
            'end_total_xp' => $endTotal,
            'top_skills' => $top,
            'skill_gains' => $skillGains,
        ];
    }

    $xpStats = tracker_build_xp_stats($pdo, $memberId, $xpWindow, (string)$week['timezone']);
    $dropHistory = tracker_build_drop_history($pdo, $memberId, (string)$week['timezone']);

    // Current skills list from latest snapshot
    $currentSkills = [
        'has_data' => false,
        'captured_at_utc' => null,
        'skills' => [],
    ];

    if ($latestSnap) {
        $rawSkills = tracker_parse_skills_json($latestSnap['skills_json']);
        $stats = tracker_extract_skill_stats($rawSkills);

        // Total (prefer skills_json.total, else compute)
        $totalLevel = null;
        $totalXp = isset($latestSnap['total_xp']) && is_numeric($latestSnap['total_xp']) ? (int)$latestSnap['total_xp'] : null;

        if (isset($rawSkills['total']) && is_array($rawSkills['total'])) {
            $tl = $rawSkills['total']['level'] ?? null;
            $tx = $rawSkills['total']['xp'] ?? null;
            if (is_numeric($tl)) $totalLevel = (int)$tl;
            if ($totalXp === null && is_numeric($tx)) $totalXp = (int)$tx;
        }

        if ($totalLevel === null) {
            $sum = 0;
            foreach (tracker_skill_order() as $sn) {
                $lvl = $stats[$sn]['level'] ?? null;
                if (is_numeric($lvl)) $sum += (int)$lvl;
            }
            $totalLevel = $sum;
        }

        $order = tracker_skill_order();
        $ordered = [];

        foreach ($order as $skillName) {
            $stRow = $stats[$skillName] ?? ['level' => null, 'xp' => null];
            $ordered[] = [
                'skill' => $skillName,
                'skill_key' => tracker_skill_key($skillName),
                'level' => $stRow['level'],
                'xp' => $stRow['xp'],
            ];
        }

        $currentSkills = [
            'has_data' => true,
            'captured_at_utc' => $latestSnap['captured_at_utc'],
            'skills' => $ordered,
            'total' => [
                'level' => $totalLevel,
                'xp' => $totalXp,
            ],
        ];
    }

    // Last pull (max of poll/snapshot/activity)
    $lastPullUtc = tracker_dtmax([$lastPolledUtc, $latestSnapUtc, $latestActivityUtc]);
    $lastPullLocal = tracker_to_local($lastPullUtc, (string)$week['timezone']);

    // Private profile flags
    $isPrivate = ((int)($member['is_private'] ?? 0) === 1);
    $privateSinceUtc = $member['private_since_utc'] ?? null;
    $privateSinceLocal = $privateSinceUtc ? tracker_to_local((string)$privateSinceUtc, (string)$week['timezone']) : null;

    // ---- Cached RuneMetrics quests + HiScores Lite misc stats ----
    $questsLive = tracker_get_cached_quests($pdo, $memberId, $clanId, (string)$member['rsn']);
    $hiscoresLite = tracker_get_cached_hiscores_lite($pdo, $memberId, $clanId, (string)$member['rsn']);

    $questsCacheUtc = $questsLive['cache']['updated_at_utc'] ?? null;
    $hiscoresCacheUtc = $hiscoresLite['cache']['updated_at_utc'] ?? null;
    $lastPullUtc = tracker_dtmax([$lastPolledUtc, $latestSnapUtc, $latestActivityUtc, $questsCacheUtc, $hiscoresCacheUtc]);
    $lastPullLocal = tracker_to_local($lastPullUtc, (string)$week['timezone']);



    tracker_json([
        'ok' => true,
        'member' => [
            'id' => $memberId,
            'rsn' => $member['rsn'],
            'rsn_normalised' => $member['rsn_normalised'],
            'rank_name' => $member['rank_name'],
            'is_active' => (int)$member['is_active'] === 1,
            'is_private' => $isPrivate,
            'private_since_utc' => $privateSinceUtc,
            'private_since_local' => $privateSinceLocal,
        ],
        'clan' => [
            'id' => $clanId,
            'name' => $member['clan_name'],
            'timezone' => $week['timezone'],
            'reset_weekday' => (int)$member['reset_weekday'],
            'reset_time' => $member['reset_time'],
        ],
        'week' => $week,
        'cap' => [
            'capped' => $capRow ? true : false,
            'capped_at_utc' => $capRow['capped_at_utc'] ?? null,
            'capped_at_local' => tracker_to_local($capRow['capped_at_utc'] ?? null, (string)$week['timezone']),
            'rule_id' => $capRow['rule_id'] ?? null,
        ],
        'visit' => [
            'visited' => $visitRow ? true : false,
            'visited_at_utc' => $visitRow['visited_at_utc'] ?? null,
            'visited_at_local' => tracker_to_local($visitRow['visited_at_utc'] ?? null, (string)$week['timezone']),
            'rule_id' => $visitRow['rule_id'] ?? null,
        ],
        'quests' => $questsLive,
        'hiscores_lite' => $hiscoresLite,
        'activity_limit' => $activityLimit,
        'activity_limit_options' => $allowedActivityLimits,

        'recent_activity' => array_map(static function(array $r) use ($week) {
            $actUtc = $r['activity_date_utc'] ?? null;
            $annUtc = $r['announced_at'] ?? null;

            return [
                'activity_date_utc' => $actUtc,
                'activity_date_local' => tracker_to_local($actUtc, (string)$week['timezone']),
                'announced_at_utc' => $annUtc,
                'announced_at_local' => tracker_to_local($annUtc, (string)$week['timezone']),
                'timezone' => $week['timezone'],
                'text' => $r['activity_text'] ?? null,
                'details' => $r['activity_details'] ?? null,
            ];
        }, $activityRows),
        'xp' => $xp,
        'xp_stats' => $xpStats,
        'drop_history' => $dropHistory,
        'xp_periods' => [
        ['value' => '24h', 'label' => 'Last 24 hours'],
        ['value' => '7d', 'label' => 'Last 7 days'],
        ['value' => '30d', 'label' => 'Last 30 days'],
        ['value' => '90d', 'label' => 'Last 90 days'],
        ['value' => 'thismonth', 'label' => 'This month'],
        ['value' => 'lastmonth', 'label' => 'Last month'],
        ['value' => 'alltime', 'label' => 'All time'],
        ['value' => 'thisweek', 'label' => 'This clan week'],
        ['value' => 'lastweek', 'label' => 'Last clan week'],
    ],
        'current_skills' => $currentSkills,
        'last_pull' => [
            'utc' => $lastPullUtc,
            'local' => $lastPullLocal,
            'timezone' => $week['timezone'],
            'sources' => [
                'last_poll_at_utc' => $lastPolledUtc ?: null,
                'last_xp_snapshot_utc' => $latestSnapUtc ?: null,
                'last_activity_utc' => $latestActivityUtc ?: null,
                'last_rm_quests_utc' => $questsCacheUtc ?: null,
                'last_hiscores_lite_utc' => $hiscoresCacheUtc ?: null,
            ],
        ],
    ]);

} catch (Throwable $e) {
    tracker_json([
        'ok' => false,
        'error' => 'Player endpoint failed',
        'hint' => $e->getMessage(),
    ], 500);
}
