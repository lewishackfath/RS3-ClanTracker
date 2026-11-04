<?php
declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/functions/time_helpers.php';
require_once __DIR__ . '/functions/member_helpers.php';
require_once __DIR__ . '/functions/skilling_pets.php';
require_once __DIR__ . '/functions/player_platform_cache.php';
require_once __DIR__ . '/functions/quest_data.php';

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
        'strip_prefixes' => ['pair of', 'some'],
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
    if ($text !== '' && preg_match('/^I\s+found\s+(?:(?:an?|some)\s+)?(.+?)(?:\.\s*)?$/i', $text, $m)) {
        return tracker_clean_drop_item_name($m[1] ?? null);
    }

    $details = trim((string)($activityDetails ?? ''));
    if ($details !== '' && preg_match('/\bdropped\s+(?:(?:an?|some)\s+)?(.+?)(?:\.\s*|$)/i', $details, $m)) {
        return tracker_clean_drop_item_name($m[1] ?? null);
    }

    return null;
}

function tracker_build_drop_history(PDO $pdo, int $memberId, string $timezone): array {
    $stmt = $pdo->prepare("\n        SELECT activity_date_utc, announced_at, activity_text, activity_details\n        FROM member_activities\n        WHERE member_id = :mid\n          AND (\n            activity_text LIKE 'I found %'\n            OR activity_details LIKE '%dropped %'\n          )\n        ORDER BY COALESCE(activity_date_utc, announced_at) DESC\n        LIMIT 10000\n    ");
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

function tracker_boss_log_default_item_aliases(): array {
    return [
        'Bandos shield' => 'Bandos warshield',
        'Hiss of Saradomin' => "Saradomin's hiss",
        'Bill (pet)' => 'Bill (pet) pet',
        'Vitalis' => 'Vitalis (pet)',
        'Zammo the Rak' => 'Zammo the Rak pet',
    ];
}

function tracker_load_item_icon_alias_pairs(): array {
    static $cache = null;
    if (is_array($cache)) return $cache;

    $pairs = [];
    $addPair = static function($from, $to) use (&$pairs): void {
        $from = tracker_clean_drop_item_name(is_scalar($from) ? (string)$from : null) ?? '';
        $to = tracker_clean_drop_item_name(is_scalar($to) ? (string)$to : null) ?? '';
        if ($from === '' || $to === '') return;
        $pairs[] = ['from' => $from, 'to' => $to];
    };

    foreach (tracker_boss_log_default_item_aliases() as $from => $to) {
        $addPair($from, $to);
    }

    $path = __DIR__ . '/../assets/activity/item_icon_alias.json';
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $json = is_string($raw) ? json_decode($raw, true) : null;

        if (is_array($json)) {
            $sourceObject = (isset($json['aliases']) && is_array($json['aliases']) && array_keys($json['aliases']) !== range(0, count($json['aliases']) - 1))
                ? $json['aliases']
                : $json;

            if (is_array($sourceObject)) {
                foreach ($sourceObject as $from => $to) {
                    if (is_string($to)) $addPair($from, $to);
                }
            }

            if (isset($json['aliases']) && is_array($json['aliases'])) {
                foreach ($json['aliases'] as $row) {
                    if (!is_array($row)) continue;
                    $addPair(
                        $row['from'] ?? $row['source'] ?? $row['runemetrics'] ?? $row['activity_name'] ?? null,
                        $row['to'] ?? $row['target'] ?? $row['wiki'] ?? $row['item_name'] ?? null
                    );
                }
            }
        }
    }

    $deduped = [];
    $seen = [];
    foreach ($pairs as $pair) {
        $fromKey = tracker_boss_log_item_key($pair['from'] ?? '');
        $toKey = tracker_boss_log_item_key($pair['to'] ?? '');
        if ($fromKey === '' || $toKey === '' || $fromKey === $toKey) continue;
        $dedupeKey = $fromKey . '|' . $toKey;
        if (isset($seen[$dedupeKey])) continue;
        $seen[$dedupeKey] = true;
        $deduped[] = [
            'from' => (string)$pair['from'],
            'to' => (string)$pair['to'],
            'from_key' => $fromKey,
            'to_key' => $toKey,
        ];
    }

    $cache = $deduped;
    return $cache;
}

function tracker_boss_log_alias_names_for_item(string $itemName): array {
    $itemKey = tracker_boss_log_item_key($itemName);
    if ($itemKey === '') return [];

    $aliases = [];
    foreach (tracker_load_item_icon_alias_pairs() as $pair) {
        $fromKey = (string)($pair['from_key'] ?? '');
        $toKey = (string)($pair['to_key'] ?? '');
        if ($fromKey === $itemKey && !empty($pair['to'])) {
            $aliases[] = (string)$pair['to'];
        }
        if ($toKey === $itemKey && !empty($pair['from'])) {
            $aliases[] = (string)$pair['from'];
        }
    }

    $out = [];
    $seen = [$itemKey => true];
    foreach ($aliases as $alias) {
        $alias = tracker_clean_drop_item_name($alias) ?? trim($alias);
        $aliasKey = tracker_boss_log_item_key($alias);
        if ($alias === '' || $aliasKey === '' || isset($seen[$aliasKey])) continue;
        $seen[$aliasKey] = true;
        $out[] = $alias;
    }

    return $out;
}

function tracker_boss_log_item_key(?string $value): string {
    $s = tracker_clean_drop_item_name($value) ?? trim((string)($value ?? ''));
    if ($s === '') return '';
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace(['’', '‘', '`'], "'", $s);
    $s = mb_strtolower($s);
    $s = preg_replace('/&/u', ' and ', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

function tracker_load_boss_log_definitions(): array {
    static $cache = null;
    if (is_array($cache)) return $cache;

    $baseDir = __DIR__ . '/../assets/boss-log';
    $indexPath = $baseDir . '/bosses.json';
    $out = [
        'version' => null,
        'source' => 'https://runescape.wiki/w/Boss_collection_log',
        'bosses' => [],
        'total_items' => 0,
    ];

    if (!is_file($indexPath)) {
        $cache = $out;
        return $cache;
    }

    $indexRaw = file_get_contents($indexPath);
    $indexJson = is_string($indexRaw) ? json_decode($indexRaw, true) : null;
    if (!is_array($indexJson)) {
        $cache = $out;
        return $cache;
    }

    $out['version'] = $indexJson['version'] ?? null;
    $out['source'] = (string)($indexJson['source'] ?? $out['source']);
    $bossRows = (isset($indexJson['bosses']) && is_array($indexJson['bosses'])) ? $indexJson['bosses'] : [];

    foreach ($bossRows as $bossRow) {
        if (!is_array($bossRow)) continue;
        $bossKey = trim((string)($bossRow['key'] ?? ''));
        $bossName = trim((string)($bossRow['name'] ?? ''));
        $file = trim((string)($bossRow['file'] ?? ''));
        if ($bossKey === '' || $bossName === '' || $file === '') continue;

        $file = str_replace(['\\', '..'], ['/', ''], $file);
        $path = $baseDir . '/' . ltrim($file, '/');
        if (!is_file($path)) continue;

        $raw = file_get_contents($path);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($json)) continue;

        $itemsRaw = (isset($json['items']) && is_array($json['items'])) ? $json['items'] : [];
        $items = [];
        foreach ($itemsRaw as $idx => $itemRow) {
            $iconName = '';
            if (is_string($itemRow)) {
                $itemName = trim($itemRow);
                $aliases = [];
                $order = $idx + 1;
            } elseif (is_array($itemRow)) {
                $itemName = trim((string)($itemRow['name'] ?? ''));
                $iconName = trim((string)($itemRow['icon_name'] ?? $itemRow['icon'] ?? $itemRow['wiki_icon'] ?? ''));
                $aliasesRaw = (isset($itemRow['aliases']) && is_array($itemRow['aliases'])) ? $itemRow['aliases'] : [];
                $aliases = [];
                foreach ($aliasesRaw as $alias) {
                    $alias = trim((string)$alias);
                    if ($alias !== '' && strcasecmp($alias, $itemName) !== 0) $aliases[] = $alias;
                }
                $order = isset($itemRow['order']) && is_numeric($itemRow['order']) ? (int)$itemRow['order'] : ($idx + 1);
            } else {
                continue;
            }

            if ($itemName === '') continue;
            foreach (tracker_boss_log_alias_names_for_item($itemName) as $alias) {
                $aliases[] = $alias;
            }

            $itemKey = tracker_boss_log_item_key($itemName);
            if ($itemKey === '') continue;

            $dedupedAliases = [];
            $seenAliases = [$itemKey => true];
            foreach ($aliases as $alias) {
                $alias = tracker_clean_drop_item_name((string)$alias) ?? trim((string)$alias);
                $aliasKey = tracker_boss_log_item_key($alias);
                if ($alias === '' || $aliasKey === '' || isset($seenAliases[$aliasKey])) continue;
                $seenAliases[$aliasKey] = true;
                $dedupedAliases[] = $alias;
            }

            $items[] = [
                'key' => $itemKey,
                'name' => $itemName,
                'icon_name' => $iconName !== '' ? $iconName : null,
                'aliases' => $dedupedAliases,
                'order' => $order,
            ];
        }

        usort($items, static fn(array $a, array $b): int => ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)));
        $out['total_items'] += count($items);
        $out['bosses'][] = [
            'key' => $bossKey,
            'name' => $bossName,
            'file' => $file,
            'order' => isset($bossRow['order']) && is_numeric($bossRow['order']) ? (int)$bossRow['order'] : count($out['bosses']) + 1,
            'items' => $items,
        ];
    }

    usort($out['bosses'], static function(array $a, array $b): int {
        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
    $cache = $out;
    return $cache;
}

function tracker_sync_member_boss_collection_log(PDO $pdo, int $memberId, int $clanId, array $definitions): array {
    $index = [];
    foreach (($definitions['bosses'] ?? []) as $boss) {
        if (!is_array($boss)) continue;
        $bossKey = (string)($boss['key'] ?? '');
        $bossName = (string)($boss['name'] ?? '');
        foreach (($boss['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $itemName = (string)($item['name'] ?? '');
            $itemKey = (string)($item['key'] ?? tracker_boss_log_item_key($itemName));
            if ($bossKey === '' || $itemKey === '' || $itemName === '') continue;

            $matchNames = array_merge([$itemName], (array)($item['aliases'] ?? []));
            foreach ($matchNames as $matchName) {
                $matchKey = tracker_boss_log_item_key((string)$matchName);
                if ($matchKey === '') continue;
                $index[$matchKey][] = [
                    'boss_key' => $bossKey,
                    'boss_name' => $bossName,
                    'item_key' => $itemKey,
                    'item_name' => $itemName,
                ];
            }
        }
    }

    if (!$index) return ['matched_activities' => 0, 'matched_items' => 0, 'stored_items' => 0];

    $stmt = $pdo->prepare("\n        SELECT id, activity_date_utc, announced_at, activity_text, activity_details\n        FROM member_activities\n        WHERE member_id = :mid\n          AND (\n            activity_text LIKE 'I found %'\n            OR activity_details LIKE '%dropped %'\n          )\n        ORDER BY COALESCE(activity_date_utc, announced_at) ASC\n        LIMIT 50000\n    ");
    $stmt->execute([':mid' => $memberId]);
    $rows = $stmt->fetchAll() ?: [];

    $found = [];
    $matchedActivities = 0;
    foreach ($rows as $row) {
        $dropName = tracker_extract_drop_item_name($row['activity_text'] ?? null, $row['activity_details'] ?? null);
        if (!$dropName) continue;

        $matchKey = tracker_boss_log_item_key($dropName);
        if ($matchKey === '' || empty($index[$matchKey])) continue;

        $matchedActivities++;
        $seenUtc = (string)(($row['activity_date_utc'] ?? null) ?: ($row['announced_at'] ?? ''));
        $activityId = isset($row['id']) && is_numeric($row['id']) ? (int)$row['id'] : null;

        foreach ($index[$matchKey] as $ref) {
            $compound = $ref['boss_key'] . '|' . $ref['item_key'];
            if (!isset($found[$compound])) {
                $found[$compound] = [
                    'boss_key' => $ref['boss_key'],
                    'boss_name' => $ref['boss_name'],
                    'item_key' => $ref['item_key'],
                    'item_name' => $ref['item_name'],
                    'drop_count' => 0,
                    'first_seen_utc' => null,
                    'last_seen_utc' => null,
                    'source_activity_id' => null,
                ];
            }

            $found[$compound]['drop_count']++;
            if ($seenUtc !== '') {
                if ($found[$compound]['first_seen_utc'] === null || strcmp($seenUtc, (string)$found[$compound]['first_seen_utc']) < 0) {
                    $found[$compound]['first_seen_utc'] = $seenUtc;
                }
                if ($found[$compound]['last_seen_utc'] === null || strcmp($seenUtc, (string)$found[$compound]['last_seen_utc']) >= 0) {
                    $found[$compound]['last_seen_utc'] = $seenUtc;
                    $found[$compound]['source_activity_id'] = $activityId;
                }
            }
        }
    }

    if (!$found) return ['matched_activities' => $matchedActivities, 'matched_items' => 0, 'stored_items' => 0];

    $upsert = $pdo->prepare("\n        INSERT INTO member_boss_collection_log\n            (member_id, member_clan_id, boss_key, boss_name, item_key, item_name, drop_count, first_seen_utc, last_seen_utc, source_activity_id)\n        VALUES\n            (:member_id, :member_clan_id, :boss_key, :boss_name, :item_key, :item_name, :drop_count, :first_seen_utc, :last_seen_utc, :source_activity_id)\n        ON DUPLICATE KEY UPDATE\n            member_clan_id = VALUES(member_clan_id),\n            boss_name = VALUES(boss_name),\n            item_name = VALUES(item_name),\n            drop_count = GREATEST(drop_count, VALUES(drop_count)),\n            first_seen_utc = CASE\n                WHEN first_seen_utc IS NULL THEN VALUES(first_seen_utc)\n                WHEN VALUES(first_seen_utc) IS NULL THEN first_seen_utc\n                WHEN VALUES(first_seen_utc) < first_seen_utc THEN VALUES(first_seen_utc)\n                ELSE first_seen_utc\n            END,\n            last_seen_utc = CASE\n                WHEN last_seen_utc IS NULL THEN VALUES(last_seen_utc)\n                WHEN VALUES(last_seen_utc) IS NULL THEN last_seen_utc\n                WHEN VALUES(last_seen_utc) > last_seen_utc THEN VALUES(last_seen_utc)\n                ELSE last_seen_utc\n            END,\n            source_activity_id = VALUES(source_activity_id),\n            updated_at = CURRENT_TIMESTAMP(3)\n    ");

    $stored = 0;
    foreach ($found as $row) {
        $upsert->execute([
            ':member_id' => $memberId,
            ':member_clan_id' => $clanId,
            ':boss_key' => $row['boss_key'],
            ':boss_name' => $row['boss_name'],
            ':item_key' => $row['item_key'],
            ':item_name' => $row['item_name'],
            ':drop_count' => (int)$row['drop_count'],
            ':first_seen_utc' => $row['first_seen_utc'],
            ':last_seen_utc' => $row['last_seen_utc'],
            ':source_activity_id' => $row['source_activity_id'],
        ]);
        $stored++;
    }

    return ['matched_activities' => $matchedActivities, 'matched_items' => count($found), 'stored_items' => $stored];
}

function tracker_build_boss_collection_log(PDO $pdo, int $memberId, int $clanId, string $timezone): array {
    $definitions = tracker_load_boss_log_definitions();
    $sync = ['matched_activities' => 0, 'matched_items' => 0, 'stored_items' => 0];
    $available = true;
    $error = null;

    try {
        $sync = tracker_sync_member_boss_collection_log($pdo, $memberId, $clanId, $definitions);
    } catch (Throwable $e) {
        $available = false;
        $error = 'Boss collection log storage is not ready. Run the updated database bootstrap to create member_boss_collection_log.';
    }

    $stored = [];
    if ($available) {
        try {
            $stmt = $pdo->prepare("\n                SELECT boss_key, item_key, drop_count, first_seen_utc, last_seen_utc, source_activity_id\n                FROM member_boss_collection_log\n                WHERE member_id = :mid\n                  AND member_clan_id = :cid\n            ");
            $stmt->execute([':mid' => $memberId, ':cid' => $clanId]);
            foreach (($stmt->fetchAll() ?: []) as $row) {
                $bossKey = (string)($row['boss_key'] ?? '');
                $itemKey = (string)($row['item_key'] ?? '');
                if ($bossKey === '' || $itemKey === '') continue;
                $stored[$bossKey][$itemKey] = $row;
            }
        } catch (Throwable $e) {
            $available = false;
            $error = 'Boss collection log storage is not ready. Run the updated database bootstrap to create member_boss_collection_log.';
        }
    }

    $bosses = [];
    $totalItems = 0;
    $foundItems = 0;
    $completeBosses = 0;

    foreach (($definitions['bosses'] ?? []) as $boss) {
        if (!is_array($boss)) continue;
        $bossKey = (string)($boss['key'] ?? '');
        $bossFound = 0;
        $items = [];

        foreach (($boss['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $itemKey = (string)($item['key'] ?? '');
            $state = ($bossKey !== '' && $itemKey !== '' && isset($stored[$bossKey][$itemKey])) ? $stored[$bossKey][$itemKey] : null;
            $isFound = is_array($state) && (int)($state['drop_count'] ?? 0) > 0;
            $totalItems++;
            if ($isFound) {
                $bossFound++;
                $foundItems++;
            }

            $firstUtc = $state['first_seen_utc'] ?? null;
            $lastUtc = $state['last_seen_utc'] ?? null;
            $items[] = [
                'key' => $itemKey,
                'name' => (string)($item['name'] ?? ''),
                'icon_name' => isset($item['icon_name']) && is_string($item['icon_name']) && trim($item['icon_name']) !== '' ? trim($item['icon_name']) : null,
                'aliases' => $item['aliases'] ?? [],
                'found' => $isFound,
                'drop_count' => $isFound ? (int)($state['drop_count'] ?? 0) : 0,
                'first_seen_utc' => $firstUtc,
                'first_seen_local' => $firstUtc ? tracker_to_local((string)$firstUtc, $timezone) : null,
                'last_seen_utc' => $lastUtc,
                'last_seen_local' => $lastUtc ? tracker_to_local((string)$lastUtc, $timezone) : null,
                'source_activity_id' => $state['source_activity_id'] ?? null,
            ];
        }

        $bossTotal = count($items);
        if ($bossTotal > 0 && $bossFound >= $bossTotal) $completeBosses++;
        $bosses[] = [
            'key' => $bossKey,
            'name' => (string)($boss['name'] ?? ''),
            'found_items' => $bossFound,
            'total_items' => $bossTotal,
            'complete' => $bossTotal > 0 && $bossFound >= $bossTotal,
            'items' => $items,
        ];
    }

    return [
        'available' => $available,
        'error' => $error,
        'version' => $definitions['version'] ?? null,
        'source' => $definitions['source'] ?? null,
        'sync' => $sync,
        'summary' => [
            'total_bosses' => count($bosses),
            'complete_bosses' => $completeBosses,
            'found_items' => $foundItems,
            'total_items' => $totalItems,
        ],
        'bosses' => $bosses,
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


function tracker_get_cached_quests(PDO $pdo, int $memberId, int $clanId, string $rsn, bool $allowRefresh = true): array {
    return tracker_get_platform_cache($pdo, $memberId, $clanId, $rsn, 'quests', $allowRefresh);
}

function tracker_get_cached_hiscores_lite(PDO $pdo, int $memberId, int $clanId, string $rsn, bool $allowRefresh = true): array {
    return tracker_get_platform_cache($pdo, $memberId, $clanId, $rsn, 'hiscores', $allowRefresh);
}

try {
    $player = tracker_get_param('player', 64);
    if ($player === '') tracker_json(['ok' => false, 'error' => 'Missing player'], 400);

    $period = tracker_get_param('period', 16);
    $deferRefreshRaw = strtolower(tracker_get_param('defer_refresh', 8));
    $allowExternalRefresh = !in_array($deferRefreshRaw, ['1', 'true', 'yes', 'y'], true);

    $activityLimitRaw = (int)(tracker_get_param('activity_limit', 8) ?: '20');
    $allowedActivityLimits = [20, 50, 100, 200];
    $activityLimit = in_array($activityLimitRaw, $allowedActivityLimits, true) ? $activityLimitRaw : 20;

    $preferredClanId = (int)(tracker_get_param('clan', 16) ?: (string)(getenv('TRACKER_CLAN_ID') ?: '0'));

    $pdo = tracker_pdo();
    rp_ensure_schema($pdo);

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

    $citadelEligible = !tracker_is_guest_rank($member['rank_name'] ?? null);
    $capRow = null;
    $visitRow = null;
    if ($citadelEligible) {
        // Guests' historical records must not appear as this clan's credit.
        $stmt = $pdo->prepare("
            SELECT capped_at_utc, rule_id
            FROM member_caps
            WHERE clan_id = :clan AND member_id = :mid AND cap_week_start_utc = :ws
            LIMIT 1
        ");
        $stmt->execute([':clan' => $clanId, ':mid' => $memberId, ':ws' => $week['week_start_utc']]);
        $capRow = $stmt->fetch() ?: null;

        $stmt = $pdo->prepare("
            SELECT visited_at_utc, rule_id
            FROM member_citadel_visits
            WHERE clan_id = :clan AND member_id = :mid AND cap_week_start_utc = :ws
            LIMIT 1
        ");
        $stmt->execute([':clan' => $clanId, ':mid' => $memberId, ':ws' => $week['week_start_utc']]);
        $visitRow = $stmt->fetch() ?: null;
    }

    // Recent activity (NEW schema: member_activities DOES have activity_text)
    $stmt = $pdo->prepare("
        SELECT id, activity_date_utc, activity_text, activity_details, announced_at
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
    $bossCollectionLog = tracker_build_boss_collection_log($pdo, $memberId, $clanId, (string)$week['timezone']);
    $skillingPets = sp_build_log($pdo, $memberId, $clanId, __DIR__ . '/../assets/skilling-pets/pets.json');

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

    // ---- Platform quests + Hiscores statistics ----
    $questsLive = tracker_get_cached_quests($pdo, $memberId, $clanId, (string)$member['rsn'], $allowExternalRefresh);
    $hiscoresLite = tracker_get_cached_hiscores_lite($pdo, $memberId, $clanId, (string)$member['rsn'], $allowExternalRefresh);

    $questsCacheUtc = $questsLive['cache']['updated_at_utc'] ?? null;
    $hiscoresCacheUtc = $hiscoresLite['cache']['updated_at_utc'] ?? null;
    $lastPullUtc = tracker_dtmax([$lastPolledUtc, $latestSnapUtc, $latestActivityUtc, $questsCacheUtc, $hiscoresCacheUtc]);
    $lastPullLocal = tracker_to_local($lastPullUtc, (string)$week['timezone']);



    tracker_json([
        'ok' => true,
        'deferred_refresh' => !$allowExternalRefresh,
        'platform_sources' => rp_fetch_states($pdo, $memberId),
        'member' => [
            'id' => $memberId,
            'rsn' => $member['rsn'],
            'rsn_normalised' => $member['rsn_normalised'],
            'rank_name' => $member['rank_name'],
            'citadel_eligible' => $citadelEligible,
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
                'id' => (int)$r['id'],
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
        'boss_collection_log' => $bossCollectionLog,
        'skilling_pets' => $skillingPets,
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
