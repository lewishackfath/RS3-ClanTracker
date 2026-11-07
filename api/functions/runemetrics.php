<?php
declare(strict_types=1);

/**
 * /functions/runemetrics.php
 *
 * Responsibilities:
 * 1) Fetch Player Events and Player Data skills independently
 * 2) Insert activities into member_activities
 *    - Match activity_announcement_rules at insert time (first match wins)
 *    - If purpose is cap_detection or visit_detection, upsert into member_caps / member_citadel_visits
 * 3) Insert XP snapshot into member_xp_snapshots
 *    - Convert Player Data fine XP to whole XP per skill
 *    - Store as JSON (SkillName => {level, xp})
 *
 * Notes:
 * - Does NOT send announcements (separate function later).
 * - Activities are deduped by (member_id, activity_hash) unique key.
 * - XP snapshots are deduped by (member_id, snapshot_hash) unique key.
 */

date_default_timezone_set('UTC');

require_once __DIR__ . '/activity_helpers.php';
require_once __DIR__ . '/platform_storage.php';
require_once __DIR__ . '/rank_up_detection.php';

/**
 * Load rank order from a JSON file.
 * Expected formats:
 *  - {"order": ["Recruit", ...]}
 *  - ["Recruit", ...]
 */
function rm_load_rank_order_from_json_file(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (is_array($decoded) && array_key_exists('order', $decoded) && is_array($decoded['order'])) {
        return array_values(array_filter(array_map('strval', $decoded['order'])));
    }
    if (is_array($decoded)) {
        // If the JSON is a simple array, treat it as the rank order.
        // Make sure it isn't an associative array.
        $isAssoc = array_keys($decoded) !== range(0, count($decoded) - 1);
        if (!$isAssoc) {
            return array_values(array_filter(array_map('strval', $decoded)));
        }
    }
    return [];
}

/* ============================================================
   Public entry points
   ============================================================ */

/**
 * Sync a member by member_id (preferred).
 *
 * @return array{
 *   ok:bool,
 *   member_id:int,
 *   clan_id:int,
 *   rsn:string,
 *   fetched_activities:int,
 *   inserted_activities:int,
 *   xp_snapshot_inserted:bool,
 *   error:?string
 * }
 */
function runemetrics_sync_member(PDO $pdo, int $memberId, int $activities = 20, ?callable $transport = null): array
{
    $result = [
        'ok' => false,
        'member_id' => $memberId,
        'clan_id' => 0,
        'rsn' => '',
        'fetched_activities' => 0,
        'inserted_activities' => 0,
        'xp_snapshot_inserted' => false,
        'cap_detected' => false,
        'status' => null,
        'error' => null,
    ];

    $capDetected = false;
    $capWeekStartUtc = null;

    try {
        $member = rm_db_get_member($pdo, $memberId);
        if (!$member) {
            $result['error'] = "Member not found: {$memberId}";
            return $result;
        }

        $result['clan_id'] = (int)$member['clan_id'];
        $result['rsn'] = (string)$member['rsn'];

        // -------------------------------------------------------------------
        // Rank reconciliation should happen on every poll (capped or not).
        // This is silent (no pings) and relies on clan roster (members_lite).
        // -------------------------------------------------------------------
        if (function_exists('ru_reconcile_member_rank_silent')) {
            try {
                $clanMini = ['id' => (int)$member['clan_id']];
                // Prefer any richer clan loader if present
                if (function_exists('rm_db_load_clan_reset')) {
                    $full = rm_db_load_clan_reset($pdo, (int)$member['clan_id']);
                    if (is_array($full) && !empty($full)) $clanMini = $full;
                }
                ru_reconcile_member_rank_silent($pdo, $clanMini, $member);
                // Reload member so downstream logic sees the reconciled rank_name
                $member = rm_db_get_member($pdo, $memberId) ?: $member;
            } catch (Throwable $e) {
                // never block polling
            }
        }


        // The legacy function name is retained for callers; both sources now use Platform APIs.
        $result = array_merge($result, rp_sync_data($pdo, $member, $transport));
        $capDetected = $result['cap_detected'];
        $capWeekStartUtc = $result['cap_week_start_utc'];

        // Rank-up detection:
        // We run this when:
        // 1) A cap activity was detected in THIS sync, OR
        // 2) The member is already marked as capped for the CURRENT cap week (even if the cap activity isn't in the last N RuneMetrics activities).
        //
        // A synthetic marker activity prevents duplicates per cap week, so this is safe to run repeatedly.
        if (!tracker_is_guest_rank($member['rank_name'] ?? null) && function_exists('detect_and_notify_rank_up')) {
            try {
                $clanFull = rm_db_load_clan_reset($pdo, (int)$member['clan_id']); // includes discord + rank settings
                if ($clanFull) {
                    // Resolve rank order
                    $rankOrder = [];
                    if (!empty($clanFull['rank_order_json'])) {
                        $decoded = json_decode((string)$clanFull['rank_order_json'], true);
                        if (is_array($decoded)) $rankOrder = array_values(array_map('strval', $decoded));
                    } elseif (!empty($clanFull['rank_order'])) {
                        $rankOrder = array_values(array_filter(array_map('trim', explode(',', (string)$clanFull['rank_order']))));
                    }

                    // Fallback: load rank order from JSON file (tracker-side config)
                    if (!$rankOrder) {
                        // Prefer ../config/ranks.json (when this file lives in /functions)
                        $rankOrder = rm_load_rank_order_from_json_file(__DIR__ . '/../config/ranks.json');
                    }
                    if (!$rankOrder) {
                        // Secondary fallback: ranks.json next to this file
                        $rankOrder = rm_load_rank_order_from_json_file(__DIR__ . '/ranks.json');
                    }

                    if ($rankOrder) {

                        // Always reconcile rank silently (capped or not capped)
                        if (function_exists('ru_reconcile_member_rank_silent')) {
                            try {
                                ru_reconcile_member_rank_silent($pdo, $clanFull, $member);
                            } catch (Throwable $e) {
                                // ignore
                            }
                        }

                        // Determine the CURRENT cap week to evaluate (prevents historical back-pings)
                        [$currentStartUtc, $currentEndUtc] = ah_cap_week_bounds_utc(
                            new DateTimeImmutable('now', new DateTimeZone('UTC')),
                            (string)$clanFull['timezone'],
                            (int)$clanFull['reset_weekday'],
                            (string)$clanFull['reset_time']
                        );

                        $capWeekStart = $currentStartUtc->format('Y-m-d H:i:s.v');
                        $startUtc = $currentStartUtc;
                        $endUtc   = $currentEndUtc;

                        // Did we detect a cap activity THIS sync, and is it in the current cap week?
                        $cappedThisWeek = false;
                        if ($capDetected && is_string($capWeekStartUtc) && $capWeekStartUtc !== '') {
                            [$capStartUtc, $capEndUtc] = ah_cap_week_bounds_utc(
                                new DateTimeImmutable($capWeekStartUtc, new DateTimeZone('UTC')),
                                (string)$clanFull['timezone'],
                                (int)$clanFull['reset_weekday'],
                                (string)$clanFull['reset_time']
                            );
                            // Only treat it as a qualifying cap if that activity belongs to the current week.
                            $cappedThisWeek = ($capStartUtc->format('Y-m-d H:i:s.v') === $currentStartUtc->format('Y-m-d H:i:s.v'));
                        }

                        // If we didn't see the cap activity (or it was from a previous week), check the caps table for the CURRENT week.
                        if (!$cappedThisWeek && $startUtc && $endUtc) {
                            $st = $pdo->prepare("
                                SELECT 1
                                FROM member_caps
                                WHERE clan_id = :clan_id
                                  AND member_id = :member_id
                                  AND capped_at_utc >= :start_utc
                                  AND capped_at_utc < :end_utc
                                LIMIT 1
                            ");
                            $st->execute([
                                ':clan_id' => (int)$member['clan_id'],
                                ':member_id' => (int)$member['id'],
                                ':start_utc' => $startUtc->format('Y-m-d H:i:s.v'),
                                ':end_utc' => $endUtc->format('Y-m-d H:i:s.v'),
                            ]);
                            $cappedThisWeek = (bool)$st->fetchColumn();
                        }

                        if ($cappedThisWeek) {
                            // Resolve bot token (optional — we still insert the synthetic marker even if token/channel are missing)
                            $botToken = '';
                            if (isset($GLOBALS['config']) && is_array($GLOBALS['config'])) {
                                $botToken = (string)($GLOBALS['config']['discord_bot_token']
                                    ?? $GLOBALS['config']['bot_token']
                                    ?? $GLOBALS['config']['discord_token']
                                    ?? '');
                            }
                            if ($botToken === '' && defined('DISCORD_BOT_TOKEN')) {
                                $botToken = (string)DISCORD_BOT_TOKEN;
                            }
                            if ($botToken === '') {
                                $botToken = (string)getenv('DISCORD_BOT_TOKEN');
                            }

                            detect_and_notify_rank_up($pdo, $clanFull, $member, $capWeekStart, $rankOrder, $botToken);
                        }
                    }
                }
            } catch (Throwable $e) {
                // Never block sync on rank-up detection; swallow errors.
            }
        }

        $result['cap_detected'] = $capDetected;
        return $result;

    } catch (Throwable $e) {
        $result['ok'] = false;
        $result['error'] = $e->getMessage();
        return $result;
    }
}

/**
 * Sync by RSN + clan_id (handy for testing).
 */
function runemetrics_sync_rsn(PDO $pdo, int $clanId, string $rsn, int $activities = 20): array
{
    $member = rm_db_find_member_by_rsn($pdo, $clanId, $rsn);
    if (!$member) {
        return [
            'ok' => false,
            'member_id' => 0,
            'clan_id' => $clanId,
            'rsn' => $rsn,
            'fetched_activities' => 0,
            'inserted_activities' => 0,
            'xp_snapshot_inserted' => false,
            'status' => null,
            'error' => "Member not found for clan_id={$clanId}, rsn={$rsn}",
        ];
    }
    return runemetrics_sync_member($pdo, (int)$member['id'], $activities);
}

/* ============================================================
   XP snapshot parsing (divide by 10)
   ============================================================ */

function rm_skill_id_map(): array
{
    return [
        0  => 'Attack',
        1  => 'Defence',
        2  => 'Strength',
        3  => 'Constitution',
        4  => 'Ranged',
        5  => 'Prayer',
        6  => 'Magic',
        7  => 'Cooking',
        8  => 'Woodcutting',
        9  => 'Fletching',
        10 => 'Fishing',
        11 => 'Firemaking',
        12 => 'Crafting',
        13 => 'Smithing',
        14 => 'Mining',
        15 => 'Herblore',
        16 => 'Agility',
        17 => 'Thieving',
        18 => 'Slayer',
        19 => 'Farming',
        20 => 'Runecrafting',
        21 => 'Hunter',
        22 => 'Construction',
        23 => 'Summoning',
        24 => 'Dungeoneering',
        25 => 'Divination',
        26 => 'Invention',
        27 => 'Archaeology',
        28 => 'Necromancy',
    ];
}

function rm_xp_div10(int $xp): int
{
    if ($xp <= 0) return 0;
    return intdiv($xp, 10);
}

/**
 * @return array{total_xp:int, skills_json:string, snapshot_hash:string}|null
 */
function rm_parse_xp_snapshot_div10(array $profile): ?array
{
    $skillvalues = $profile['skillvalues'] ?? null;
    if (!is_array($skillvalues)) {
        return null;
    }

    $map = rm_skill_id_map();
    $skills = [];
    $totalLevel = 0;

    foreach ($skillvalues as $row) {
        if (!is_array($row)) continue;
        $id = isset($row['id']) ? (int)$row['id'] : -1;
        if (!isset($map[$id])) continue;

        $level = isset($row['level']) && is_numeric($row['level']) ? (int)$row['level'] : 0;
        $xpRaw = isset($row['xp']) && is_numeric($row['xp']) ? (int)$row['xp'] : 0;

        $skills[$map[$id]] = [
            'level' => max(0, $level),
            'xp'    => rm_xp_div10(max(0, $xpRaw)),
        ];

        // total level across all skills (excludes "Overall" because we don't map it)
        $totalLevel += max(0, $level);
    }

    if (empty($skills)) {
        return null;
    }

    $totalXp = 0;
    if (isset($profile['totalxp']) && is_numeric($profile['totalxp'])) {
        $totalXp = $profile['totalxp'];
    } else {
        foreach ($skills as $s) $totalXp += (int)$s['xp'];
    }

    // If RuneMetrics provides a total skill/level field, prefer it; otherwise use summed levels.
    if (isset($profile['totalskill']) && is_numeric($profile['totalskill'])) {
        $totalLevel = (int)$profile['totalskill'];
    } elseif (isset($profile['totallevel']) && is_numeric($profile['totallevel'])) {
        $totalLevel = (int)$profile['totallevel'];
    }

    // Insert total as a skill-like entry (level + xp)
    $skills = array_merge([
        'total' => [
            'level' => (int)$totalLevel,
            'xp'    => (int)$totalXp,
        ],
    ], $skills);

    ksort($skills);
    $skillsJson = json_encode($skills, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($skillsJson) || $skillsJson === '') {
        return null;
    }

    $hash = hash('sha256', $totalXp . '|' . $skillsJson);

    return [
        'total_xp' => $totalXp,
        'skills_json' => $skillsJson,
        'snapshot_hash' => $hash,
    ];
}

/* ============================================================
   Activity parsing
   ============================================================ */

function rm_parse_activity_date_utc(string $dateStr): DateTimeImmutable
{
    $dateStr = trim($dateStr);

    // RuneMetrics activity timestamps are supplied in UK local time, not UTC.
    // Treat the source value as Europe/London so BST/GMT daylight-saving rules
    // are applied correctly, then store the converted instant as UTC.
    $sourceTz = new DateTimeZone('Europe/London');
    $utcTz = new DateTimeZone('UTC');

    $formats = [
        'd-M-Y H:i:s',
        'd-M-Y H:i',
        'j-M-Y H:i:s',
        'j-M-Y H:i',
    ];

    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $dateStr, $sourceTz);
        if ($dt instanceof DateTimeImmutable) {
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors === false || (((int)($errors['warning_count'] ?? 0)) === 0 && ((int)($errors['error_count'] ?? 0)) === 0)) {
                return $dt
                    ->setTime((int)$dt->format('H'), (int)$dt->format('i'), (int)$dt->format('s'), 0)
                    ->setTimezone($utcTz);
            }
        }
    }

    try {
        return (new DateTimeImmutable($dateStr, $sourceTz))->setTimezone($utcTz);
    } catch (Throwable) {
        throw new RuntimeException("Unable to parse activity date: {$dateStr}");
    }
}

function rm_activity_hash(int $memberId, string $dateUtc, string $text, string $details): string
{
    return hash('sha256', $memberId . '|' . $dateUtc . '|' . $text . '|' . $details);
}

function rm_trim(string $s, int $maxLen): string
{
    $s = trim($s);
    if ($s === '') return '';
    if (mb_strlen($s, 'UTF-8') <= $maxLen) return $s;
    return mb_substr($s, 0, $maxLen, 'UTF-8');
}

/* ============================================================
   DB helpers
   ============================================================ */

function rm_db_get_member(PDO $pdo, int $memberId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, clan_id, rsn, rsn_normalised, rank_name
        FROM members
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $memberId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}


function rm_db_mark_member_private(PDO $pdo, int $memberId): void
{
    try {
        $st = $pdo->prepare("
            UPDATE members
            SET
                is_private = 1,
                private_since_utc = IF(is_private = 0 OR private_since_utc IS NULL, UTC_TIMESTAMP(3), private_since_utc)
            WHERE id = :id
        ");
        $st->execute([':id' => $memberId]);
    } catch (Throwable $e) {
        // Schema might not be migrated yet; ignore safely.
    }
}

function rm_db_clear_member_private(PDO $pdo, int $memberId): void
{
    try {
        $st = $pdo->prepare("
            UPDATE members
            SET
                is_private = 0,
                private_since_utc = NULL
            WHERE id = :id
              AND is_private = 1
        ");
        $st->execute([':id' => $memberId]);
    } catch (Throwable $e) {
        // Schema might not be migrated yet; ignore safely.
    }
}

function rm_db_find_member_by_rsn(PDO $pdo, int $clanId, string $rsn): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, clan_id, rsn, rsn_normalised, rank_name
        FROM members
        WHERE clan_id = :clan_id AND rsn = :rsn
        LIMIT 1
    ");
    $stmt->execute([':clan_id' => $clanId, ':rsn' => $rsn]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function rm_db_load_clan_reset(PDO $pdo, int $clanId): ?array
{
    $st = $pdo->prepare("SELECT * FROM clans WHERE id = :id LIMIT 1");
    $st->execute([':id' => $clanId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Insert activities with inline processing:
 * - match rules (sets rule_id)
 * - upsert caps/visits based on purpose
 *
 * @return int inserted count
 */
function rm_db_insert_activities_with_processing(PDO $pdo, int $memberId, int $clanId, array $activities, bool &$capDetected, ?string &$capWeekStartUtc = null): int
{
    $inserted = 0;
    // set by reference for the caller
    $capDetected = false;
    $capWeekStartUtc = null;

    foreach ($activities as $event) {
        if (isset($event['type'])) { rp_ensure_schema($pdo); break; }
    }
    $clan = rm_db_load_clan_reset($pdo, $clanId);
    if (!$clan) {
        throw new RuntimeException("Clan not found: {$clanId}");
    }

    $member = rm_db_get_member($pdo, $memberId);
    if (!$member || (int)$member['clan_id'] !== $clanId) {
        throw new RuntimeException("Member does not belong to clan: {$memberId}");
    }
    $isGuest = tracker_is_guest_rank($member['rank_name'] ?? null);
    $rules = ah_load_enabled_rules($pdo, $clanId);

    $stInsertActivity = $pdo->prepare("
        INSERT INTO member_activities
          (member_id, member_clan_id, activity_hash, activity_date_utc, activity_text, activity_details, rule_id, is_announced, announced_at, created_at)
        VALUES
          (:member_id, :clan_id, :hash, :date_utc, :text, :details, :rule_id, :is_announced, NULL, CURRENT_TIMESTAMP(3))
        ON DUPLICATE KEY UPDATE
          rule_id = IFNULL(member_activities.rule_id, VALUES(rule_id)),
          is_announced = GREATEST(member_activities.is_announced, VALUES(is_announced))
    ");

    // Resolve the persisted ID even when this poll repeats an existing activity.
    $stActivityId = $pdo->prepare("SELECT id FROM member_activities WHERE member_id = :member_id AND activity_hash = :hash");

    // Preserve the original activity and week on either unique-key conflict.
    $stCap = $pdo->prepare("
        INSERT INTO member_caps
          (clan_id, member_id, activity_id, cap_week_start_utc, cap_week_end_utc, capped_at_utc, created_at)
        VALUES
          (:clan_id, :member_id, :activity_id, :start_utc, :end_utc, :at_utc, CURRENT_TIMESTAMP(3))
        ON DUPLICATE KEY UPDATE
          id = member_caps.id
    ");

    $stVisit = $pdo->prepare("
        INSERT INTO member_citadel_visits
          (clan_id, member_id, activity_id, cap_week_start_utc, cap_week_end_utc, visited_at_utc, created_at)
        VALUES
          (:clan_id, :member_id, :activity_id, :start_utc, :end_utc, :at_utc, CURRENT_TIMESTAMP(3))
        ON DUPLICATE KEY UPDATE
          id = member_citadel_visits.id
    ");

    $pdo->beginTransaction();
    try {
        $memberLock = $pdo->prepare('SELECT id FROM members WHERE id = ? FOR UPDATE');
        $memberLock->execute([$memberId]);
        foreach ($activities as $a) {
            if (!is_array($a)) continue;

            $text = rm_trim((string)($a['text'] ?? ''), 255);
            $details = (string)($a['details'] ?? '');
            $dateStr = (string)($a['date'] ?? '');

            if ($text === '' || trim($dateStr) === '') continue;

            $dtUtc = rm_parse_activity_date_utc($dateStr);
            $dateUtc = $dtUtc->format('Y-m-d H:i:s.v');

            $hash = rm_activity_hash($memberId, $dateUtc, $text, $details);
            $platformHash = $hash;
            [$hash, $dateUtc] = rp_resolve_event($pdo, $memberId, $clanId, $a, $activities, $hash, $dateUtc);
            $dtUtc = new DateTimeImmutable($dateUtc, new DateTimeZone('UTC'));

            $matchedRule = ah_match_rule($text, $details, $rules);
            $ruleId = $matchedRule ? (int)$matchedRule['id'] : null;
            $purpose = (string)($matchedRule['purpose'] ?? '');
            $isCitadel = $purpose === 'cap_detection' || $purpose === 'visit_detection';

            $stInsertActivity->execute([
                ':member_id' => $memberId,
                ':clan_id' => $clanId,
                ':hash' => $hash,
                ':date_utc' => $dateUtc,
                ':text' => $text,
                ':details' => ($details !== '' ? $details : null),
                ':rule_id' => $ruleId,
                // Keep the personal activity, but consume guest citadel rules
                // without queuing a clan announcement or awarding clan credit.
                ':is_announced' => (int)($isGuest && $isCitadel),
            ]);

            if ((int)$stInsertActivity->rowCount() === 1) {
                $inserted++;
            }

            rp_record_event($pdo, $memberId, $hash, $platformHash, $a);

            if ($matchedRule && !$isGuest) {
                $purpose = (string)$matchedRule['purpose'];
                if ($purpose === 'cap_detection' || $purpose === 'visit_detection') {
                    [$startUtc, $endUtc] = ah_cap_week_bounds_utc(
                        $dtUtc,
                        (string)$clan['timezone'],
                        (int)$clan['reset_weekday'],
                        (string)$clan['reset_time']
                    );

                    $stActivityId->execute([':member_id' => $memberId, ':hash' => $hash]);
                    $activityId = $stActivityId->fetchColumn();
                    if ($activityId === false) {
                        throw new RuntimeException('Persisted activity could not be found');
                    }

                    $payload = [
                        ':clan_id' => $clanId,
                        ':member_id' => $memberId,
                        ':activity_id' => (int)$activityId,
                        ':start_utc' => $startUtc->format('Y-m-d H:i:s.v'),
                        ':end_utc' => $endUtc->format('Y-m-d H:i:s.v'),
                        ':at_utc' => $dtUtc->format('Y-m-d H:i:s.v'),
                    ];

                    if ($purpose === 'cap_detection') {
                        $stCap->execute($payload);
                        // A replay under a changed reset must not signal a new cap week.
                        if ($stCap->rowCount() === 1) {
                            $capDetected = true;
                            $capWeekStartUtc = $payload[':start_utc'];
                        }
                    } else {
                        $stVisit->execute($payload);
                    }
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return $inserted;
}

/**
 * Insert XP snapshot (deduped by unique key on member_id + snapshot_hash).
 *
 * @return bool true if inserted, false if duplicate
 */
function rm_db_insert_xp_snapshot(PDO $pdo, int $memberId, int $totalXp, string $skillsJson, string $snapshotHash): bool
{
    $stmt = $pdo->prepare("
        INSERT INTO member_xp_snapshots
          (member_id, total_xp, skills_json, snapshot_hash, captured_at_utc, created_at)
        VALUES
          (:member_id, :total_xp, :skills_json, :hash, UTC_TIMESTAMP(3), CURRENT_TIMESTAMP(3))
        ON DUPLICATE KEY UPDATE
          id = id
    ");

    $stmt->execute([
        ':member_id' => $memberId,
        ':total_xp' => $totalXp,
        ':skills_json' => $skillsJson,
        ':hash' => $snapshotHash,
    ]);

    return ((int)$stmt->rowCount() === 1);
}
