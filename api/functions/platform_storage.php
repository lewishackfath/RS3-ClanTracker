<?php
declare(strict_types=1);
require_once __DIR__ . '/platform_api.php';

// Keep identical in both repositories. All migration steps are additive/idempotent.
function rp_lock(PDO $pdo, string $key, int $wait = 10): string
{
    $name = 'rs-platform:' . substr(hash('sha256', (string)$pdo->query('SELECT DATABASE()')->fetchColumn() . ':' . $key), 0, 48);
    $st = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $st->execute([$name, $wait]);
    if ((int)$st->fetchColumn() !== 1) throw new RuntimeException('Platform sync is already running; retry later');
    return $name;
}

function rp_unlock(PDO $pdo, string $name): void
{
    $st = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $st->execute([$name]);
}

function rp_ensure_schema(PDO $pdo): void
{
    static $ready;
    $ready ??= new WeakMap();
    if (isset($ready[$pdo])) return;
    $lock = rp_lock($pdo, 'schema');
    try {
        rp_rate_limit_schema($pdo);
        $memberType = $pdo->query("SHOW COLUMNS FROM members LIKE 'id'")->fetch(PDO::FETCH_ASSOC)['Type'];
        if (!preg_match('/^(?:bigint|int|mediumint|smallint)(?:\(\d+\))?(?: unsigned)?$/iD', $memberType)) {
            throw new RuntimeException('Unsupported member ID type');
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS member_platform_fetches (
            member_id {$memberType} NOT NULL, source VARCHAR(16) NOT NULL, rsn_key VARCHAR(64) NOT NULL,
            result_json LONGTEXT NOT NULL, data_json LONGTEXT NULL,
            attempted_at_utc DATETIME(3) NOT NULL, fetched_at_utc DATETIME(3) NULL,
            retry_at_utc DATETIME(3) NULL,
            PRIMARY KEY (member_id, source),
            CONSTRAINT fk_platform_fetch_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $columns = $pdo->query('SHOW COLUMNS FROM member_activities')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['platform_event_hash' => 'CHAR(64) NULL', 'platform_event_type' => 'VARCHAR(100) NULL',
            'platform_event_date_utc' => 'DATETIME(3) NULL'] as $column => $definition) {
            if (!in_array($column, $columns, true)) $pdo->exec("ALTER TABLE member_activities ADD COLUMN {$column} {$definition}");
        }
        $index = $pdo->query("SHOW INDEX FROM member_activities WHERE Key_name = 'uk_platform_event'")->fetch();
        if (!$index) $pdo->exec('ALTER TABLE member_activities ADD UNIQUE KEY uk_platform_event (member_id, platform_event_hash)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS platform_migrations (version INT PRIMARY KEY, applied_at_utc DATETIME NOT NULL) ENGINE=InnoDB');
        if (!$pdo->query('SELECT version FROM platform_migrations WHERE version = 1')->fetchColumn()) {
            $pdo->beginTransaction();
            try {
                // Release obsolete private/post-cap waits; keep error/rate-limit backoffs.
                $pdo->exec("UPDATE member_poll_state s JOIN members m ON m.id = s.member_id
                    SET s.next_poll_at_utc = UTC_TIMESTAMP(3)
                    WHERE m.is_active = 1 AND s.next_poll_at_utc > UTC_TIMESTAMP(3)
                      AND (m.is_private = 1 OR s.last_http_status = 200)");
                $pdo->exec('UPDATE members SET is_private = 0, private_since_utc = NULL WHERE is_private = 1 OR private_since_utc IS NOT NULL');
                $pdo->exec('INSERT INTO platform_migrations VALUES (1, UTC_TIMESTAMP())');
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack(); throw $e;
            }
        }
        $ready[$pdo] = true;
    } finally { rp_unlock($pdo, $lock); }
}

function rp_cached_fetch(PDO $pdo, int $memberId, string $source, string $rsn, int $ttl = 300, ?callable $transport = null): array
{
    rp_ensure_schema($pdo);
    $lock = rp_lock($pdo, $memberId . ':' . $source);
    try {
        $st = $pdo->prepare('SELECT * FROM member_platform_fetches WHERE member_id = ? AND source = ?');
        $st->execute([$memberId, $source]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['rsn_key'] !== rp_normalise_name($rsn)) $row = false;
        $previous = $row ? json_decode($row['result_json'], true) : null;
        $fresh = $row && !empty($previous['ok']) && strtotime($row['fetched_at_utc'] . ' UTC') > time() - $ttl;
        $backoff = $row && empty($previous['ok']) && strtotime(($row['retry_at_utc'] ?? '') . ' UTC') > time();
        if ($fresh || $backoff) {
            return array_merge($previous, ['data' => $row['data_json'] ? json_decode($row['data_json'], true) : null,
                'cache_hit' => true, 'fetched_at_utc' => $row['fetched_at_utc'],
                'attempted_at_utc' => $row['attempted_at_utc'], 'retry_at_utc' => $row['retry_at_utc']]);
        }
        $result = rp_request($source, $rsn, $transport, $pdo);
        $now = gmdate('Y-m-d H:i:s');
        $data = $result['ok'] ? $result['data'] : ($row && $row['data_json'] ? json_decode($row['data_json'], true) : null);
        $fetched = $result['ok'] ? $now : ($row['fetched_at_utc'] ?? null);
        $delay = in_array($result['error'], ['PLAYER_NOT_FOUND','PLAYER_NOT_MEMBER'], true) ? 900 : 300;
        $retry = $result['ok'] ? null : gmdate('Y-m-d H:i:s', time() + max($delay, $result['retry_after']));
        $stored = $result; unset($stored['data']);
        $st = $pdo->prepare('INSERT INTO member_platform_fetches
            (member_id,source,rsn_key,result_json,data_json,attempted_at_utc,fetched_at_utc,retry_at_utc)
            VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE rsn_key=VALUES(rsn_key), result_json=VALUES(result_json),
            data_json=VALUES(data_json), attempted_at_utc=VALUES(attempted_at_utc), fetched_at_utc=VALUES(fetched_at_utc), retry_at_utc=VALUES(retry_at_utc)');
        $st->execute([$memberId,$source,rp_normalise_name($rsn),json_encode($stored, JSON_THROW_ON_ERROR),
            $data === null ? null : json_encode($data, JSON_THROW_ON_ERROR),$now,$fetched,$retry]);
        return array_merge($result, ['data' => $data, 'cache_hit' => false, 'fetched_at_utc' => $fetched,
            'attempted_at_utc' => $now, 'retry_at_utc' => $retry]);
    } finally { rp_unlock($pdo, $lock); }
}

function rp_fetch_states(PDO $pdo, int $memberId): array
{
    rp_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT f.*,m.rsn FROM member_platform_fetches f JOIN members m ON m.id=f.member_id WHERE f.member_id=?');
    $st->execute([$memberId]);
    $states = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['rsn_key'] !== rp_normalise_name($row['rsn'])) continue;
        $result = json_decode($row['result_json'], true) ?: [];
        $states[$row['source']] = ['ok' => (bool)($result['ok'] ?? false), 'http_code' => $result['http_code'] ?? null,
            'error' => $result['error'] ?? null, 'processing_error' => $result['processing_error'] ?? null,
            'last_attempt_at_utc' => $row['attempted_at_utc'],
            'last_success_at_utc' => $row['fetched_at_utc'], 'retry_at_utc' => $row['retry_at_utc']];
    }
    return $states;
}

function rp_sync_due(array $states, int $interval = 300): bool
{
    foreach (['events','hiscores'] as $source) {
        $state = $states[$source] ?? [];
        if (!empty($state['retry_at_utc']) && strtotime($state['retry_at_utc'] . ' UTC') > time()) continue;
        $last = $state['last_success_at_utc'] ?? null;
        if (!$last || strtotime($last . ' UTC') <= time() - $interval) return true;
    }
    return false;
}

/** Return the original hash/date when a precise event overlaps one legacy record. */
function rp_resolve_event(PDO $pdo, int $memberId, int $clanId, array $event, array $batch, string $hash, string $date): array
{
    if (!isset($event['type'])) return [$hash, $date];
    $text = rm_trim($event['text'],255); $details = $event['details'];
    $st = $pdo->prepare('SELECT activity_hash,activity_date_utc FROM member_activities
        WHERE member_id=? AND member_clan_id=? AND platform_event_hash=?');
    $st->execute([$memberId,$clanId,$hash]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) return [$row['activity_hash'], $row['activity_date_utc']];
    // A manual member remap preserves old hashes. The precise event signature
    // still identifies the transferred event under the destination member ID.
    $st = $pdo->prepare('SELECT activity_hash,activity_date_utc FROM member_activities
        WHERE member_id=? AND member_clan_id=? AND platform_event_hash IS NOT NULL
          AND platform_event_date_utc=? AND BINARY activity_text=BINARY ?
          AND BINARY COALESCE(activity_details,\'\')=BINARY ? ORDER BY id LIMIT 1');
    $st->execute([$memberId,$clanId,$date,$text,$details]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) return [$row['activity_hash'], $row['activity_date_utc']];
    $minute = substr($date,0,16);
    $start = $minute . ':00.000';
    $end = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->modify('+1 minute')->format('Y-m-d H:i:s.v');
    $st = $pdo->prepare('SELECT activity_hash,activity_date_utc FROM member_activities
        WHERE member_id=? AND member_clan_id=? AND platform_event_hash IS NULL
          AND activity_date_utc >= ? AND activity_date_utc < ?
          AND BINARY activity_text = BINARY ? AND BINARY COALESCE(activity_details,\'\') = BINARY ? FOR UPDATE');
    $st->execute([$memberId,$clanId,$start,$end,$text,$details]);
    $legacy = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$legacy) return [$hash,$date];
    $matches = 0;
    foreach ($batch as $candidate) {
        if (isset($candidate['type']) && rm_trim($candidate['text'],255) === $text && $candidate['details'] === $details &&
            rp_event_date($candidate['date'])->format('Y-m-d H:i') === $minute) $matches++;
    }
    if (count($legacy) !== 1 || $matches !== 1) {
        throw new RuntimeException('AMBIGUOUS_LEGACY_EVENT: reconcile repeated events in ' . $minute . ' before retrying');
    }
    return [$legacy[0]['activity_hash'], $legacy[0]['activity_date_utc']];
}

function rp_record_event(PDO $pdo, int $memberId, string $storedHash, string $platformHash, array $event): void
{
    if (!isset($event['type'])) return;
    $st = $pdo->prepare('UPDATE member_activities SET platform_event_hash=?,platform_event_type=?,platform_event_date_utc=?
        WHERE member_id=? AND activity_hash=?');
    $st->execute([$platformHash,$event['type'],rp_event_date($event['date'])->format('Y-m-d H:i:s.v'),$memberId,$storedHash]);
}

function rp_check_snapshot(PDO $pdo, int $memberId, ?array $snapshot): void
{
    if ($snapshot === null) throw new RuntimeException('INCOMPLETE_HISCORES: retaining the last complete XP snapshot');
    $st = $pdo->prepare('SELECT total_xp, skills_json FROM member_xp_snapshots WHERE member_id=? ORDER BY captured_at_utc DESC,id DESC LIMIT 1');
    $st->execute([$memberId]);
    $last = $st->fetch(PDO::FETCH_ASSOC);
    if (!$last) return;
    $before = json_decode($last['skills_json'],true) ?: [];
    $after = json_decode($snapshot['skills_json'],true);
    if ($snapshot['total_xp'] < (int)$last['total_xp']) throw new RuntimeException('HISCORES_BEHIND: retaining the newer XP snapshot');
    foreach (rp_skill_names() as $name) {
        if (isset($before[$name]['xp']) && $after[$name]['xp'] < (int)$before[$name]['xp']) {
            throw new RuntimeException('HISCORES_BEHIND: retaining the newer ' . $name . ' snapshot');
        }
    }
}

function rp_processing_status(PDO $pdo, int $memberId, string $source, ?string $error): void
{
    $lock = rp_lock($pdo, $memberId . ':' . $source);
    try {
    $st = $pdo->prepare('SELECT result_json FROM member_platform_fetches WHERE member_id=? AND source=?');
    $st->execute([$memberId,$source]);
    $json = $st->fetchColumn();
    if (!$json) return;
    $result = json_decode($json,true);
    $result['processing_error'] = $error;
    $st = $pdo->prepare('UPDATE member_platform_fetches SET result_json=? WHERE member_id=? AND source=?');
    $st->execute([json_encode($result,JSON_THROW_ON_ERROR),$memberId,$source]);
    } finally { rp_unlock($pdo,$lock); }
}

/** Successful sources are committed even if another source or processor fails. */
function rp_sync_data(PDO $pdo, array $member, ?callable $transport = null): array
{
    rp_ensure_schema($pdo);
    $id = (int)$member['id']; $clan = (int)$member['clan_id'];
    $result = ['ok' => true, 'sources' => [], 'fetched_activities' => 0, 'inserted_activities' => 0,
        'xp_snapshot_inserted' => false, 'cap_detected' => false, 'cap_week_start_utc' => null,
        'status' => 'ok', 'error' => null];
    $lock = rp_lock($pdo, $id . ':sync');
    try {
        rm_db_clear_member_private($pdo,$id);
        foreach (['events','hiscores'] as $source) {
            try {
                $fetch = rp_cached_fetch($pdo,$id,$source,(string)$member['rsn'],300,$transport);
                $status = $fetch; unset($status['data']);
                $result['sources'][$source] = $status;
                if (!$fetch['ok']) {
                    if (in_array($fetch['error'],['PLAYER_NOT_FOUND','PLAYER_NOT_MEMBER'],true)) continue;
                    throw new RuntimeException($fetch['message']);
                }
                if ($source === 'events') {
                    $events = $fetch['data']['events'];
                    $result['fetched_activities'] = count($events);
                    $result['inserted_activities'] = rm_db_insert_activities_with_processing($pdo,$id,$clan,$events,
                        $result['cap_detected'],$result['cap_week_start_utc']);
                } else {
                    $snapshot = rp_hiscores($fetch['data'])['snapshot'];
                    rp_check_snapshot($pdo,$id,$snapshot);
                    $result['xp_snapshot_inserted'] = rm_db_insert_xp_snapshot($pdo,$id,$snapshot['total_xp'],$snapshot['skills_json'],$snapshot['snapshot_hash']);
                }
                $result['sources'][$source]['processed_ok'] = true;
                $result['sources'][$source]['processing_error'] = null;
                rp_processing_status($pdo,$id,$source,null);
            } catch (Throwable $e) {
                $result['ok'] = false;
                $result['sources'][$source]['processing_error'] = $e->getMessage();
                $result['error'] = trim(($result['error'] ?? '') . ' ' . $source . ': ' . $e->getMessage());
                rp_processing_status($pdo,$id,$source,$e->getMessage());
            }
        }
        if (!$result['ok']) $result['status'] = 'partial_failure';
        elseif (empty($result['sources']['events']['ok']) || empty($result['sources']['hiscores']['ok'])) $result['status'] = 'source_unavailable';
        return $result;
    } finally { rp_unlock($pdo,$lock); }
}
