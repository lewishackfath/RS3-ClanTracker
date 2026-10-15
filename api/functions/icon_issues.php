<?php
declare(strict_types=1);

// Keep this database contract identical to RS3-API/functions/icon_issues.php.
function ii_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_icon_issues (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        source_key VARCHAR(64) NOT NULL,
        issue_key CHAR(64) NOT NULL,
        category VARCHAR(16) NOT NULL,
        display_name VARCHAR(255) NOT NULL,
        status VARCHAR(16) NOT NULL,
        reason VARCHAR(64) NOT NULL,
        sample_activity_id BIGINT NOT NULL,
        sample_text TEXT NOT NULL,
        sample_details TEXT NOT NULL,
        attempted_json JSON NOT NULL,
        occurrence_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        first_seen_at DATETIME(6) NOT NULL,
        last_seen_at DATETIME(6) NOT NULL,
        last_checked_at DATETIME(6) NOT NULL,
        next_check_at DATETIME(6) NOT NULL,
        recheck_requested_at DATETIME(6) NULL,
        resolved_at DATETIME(6) NULL,
        UNIQUE KEY uk_icon_issue (source_key, issue_key),
        KEY idx_icon_status (status, last_seen_at),
        KEY idx_icon_due (source_key, next_check_at),
        KEY idx_icon_requested (source_key, recheck_requested_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_icon_checks (
        source_key VARCHAR(64) NOT NULL,
        activity_id BIGINT NOT NULL,
        last_checked_at DATETIME(6) NOT NULL,
        PRIMARY KEY (source_key, activity_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_icon_sightings (
        issue_id BIGINT UNSIGNED NOT NULL,
        activity_id BIGINT NOT NULL,
        PRIMARY KEY (issue_id, activity_id),
        CONSTRAINT fk_icon_sighting_issue FOREIGN KEY (issue_id) REFERENCES activity_icon_issues(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_icon_workers (
        source_key VARCHAR(64) NOT NULL PRIMARY KEY,
        last_started_at DATETIME(6) NULL,
        last_finished_at DATETIME(6) NULL,
        last_error VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ii_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
}

function ii_source(): string
{
    $source = strtolower(trim((string)(getenv('TRACKER_ICON_SOURCE') ?: 'tracker')));
    if (!preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $source)) throw new RuntimeException('Invalid TRACKER_ICON_SOURCE');
    return $source;
}

/**
 * Record only server-verified activities, never browser-supplied icon claims.
 * Start time prevents an older slow lookup overwriting a newer successful fix.
 * Called outside feed/polling transactions; callers isolate logging failures.
 */
function ii_record(PDO $pdo, string $source, array $activity, array $result, string $started, ?int $recheckId = null): void
{
    if ($pdo->inTransaction()) throw new LogicException('Icon logging must use its own transaction');
    $missing = $result['status'] !== 'resolved';
    $status = $result['status'] === 'temporary' ? 'retrying' : ($missing ? 'open' : 'resolved');
    $next = (new DateTimeImmutable($started, new DateTimeZone('UTC')))
        ->modify($status === 'retrying' ? '+5 minutes' : ($missing ? '+6 hours' : '+1 day'))->format('Y-m-d H:i:s.u');
    $pdo->beginTransaction();
    try {
        if ($missing && $recheckId === null) {
            $st = $pdo->prepare("INSERT INTO activity_icon_issues
                (source_key, issue_key, category, display_name, status, reason, sample_activity_id,
                 sample_text, sample_details, attempted_json, first_seen_at, last_seen_at, last_checked_at, next_check_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE id = activity_icon_issues.id");
            $st->execute([$source, $result['key'], $result['kind'], $result['name'], $status, $result['reason'],
                $activity['id'], $activity['activity_text'] ?? '', $activity['activity_details'] ?? '',
                json_encode($result['attempts'], JSON_THROW_ON_ERROR), $started, $started, $started, $next]);
        }
        $st = $pdo->prepare('SELECT * FROM activity_icon_issues WHERE source_key = ? AND ' .
            ($recheckId === null ? 'issue_key = ?' : 'id = ?') . ' FOR UPDATE');
        $st->execute([$source, $recheckId ?? $result['key']]);
        $issue = $st->fetch(PDO::FETCH_ASSOC);
        $issueId = $issue ? (int)$issue['id'] : null;
        if ($issue && strcmp($issue['last_checked_at'], $started) <= 0) {
            // A temporary outage does not erase an already confirmed missing asset.
            if ($status === 'retrying' && $issue['status'] === 'open') $status = 'open';
            $st = $pdo->prepare("UPDATE activity_icon_issues SET category = ?, display_name = ?, status = ?, reason = ?,
                sample_activity_id = ?, sample_text = ?, sample_details = ?, attempted_json = ?,
                last_seen_at = IF(?, ?, last_seen_at), last_checked_at = ?, next_check_at = ?,
                resolved_at = IF(? = 'resolved', COALESCE(resolved_at, ?), NULL),
                recheck_requested_at = IF(? AND recheck_requested_at <= ?, NULL, recheck_requested_at)
                WHERE id = ?");
            $st->execute([$result['kind'], $result['name'], $status, $result['reason'], $activity['id'],
                $activity['activity_text'] ?? '', $activity['activity_details'] ?? '',
                json_encode($result['attempts'], JSON_THROW_ON_ERROR), (int)$missing, $started, $started, $next,
                $status, $started, (int)($recheckId !== null || !$missing), $started, $issueId]);
        }
        $st = $pdo->prepare("INSERT INTO activity_icon_checks (source_key, activity_id, last_checked_at)
            VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_checked_at = GREATEST(last_checked_at, VALUES(last_checked_at))");
        $st->execute([$source, $activity['id'], $started]);
        if ($issueId !== null && $missing) {
            $st = $pdo->prepare('INSERT IGNORE INTO activity_icon_sightings (issue_id, activity_id) VALUES (?, ?)');
            $st->execute([$issueId, $activity['id']]);
            if ($st->rowCount() === 1) {
                $st = $pdo->prepare('UPDATE activity_icon_issues SET occurrence_count = occurrence_count + 1 WHERE id = ?');
                $st->execute([$issueId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Authenticated admin queues work; only the tracker worker resolves it. */
function ii_queue(PDO $pdo, ?int $id, string $source = ''): int
{
    if ($id !== null && $id < 1) throw new InvalidArgumentException('Invalid icon issue');
    $where = $id !== null ? 'id = ?' : "status <> 'resolved'";
    $params = $id !== null ? [$id] : [];
    if ($source !== '') { $where .= ' AND source_key = ?'; $params[] = $source; }
    $st = $pdo->prepare("UPDATE activity_icon_issues SET recheck_requested_at = UTC_TIMESTAMP(6) WHERE {$where}");
    $st->execute($params);
    return $st->rowCount();
}
