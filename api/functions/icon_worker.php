<?php
declare(strict_types=1);

require_once __DIR__ . '/activity_icons.php';
require_once __DIR__ . '/icon_issues.php';

/** Bounded worker, safe to run repeatedly. Queued work takes priority. */
function iw_run(PDO $pdo, string $root, string $source, int $clanId = 0, int $limit = 100, int $seconds = 45, ?callable $resolver = null): array
{
    $limit = max(1, min(1000, $limit));
    $deadline = microtime(true) + max(1, min(300, $seconds));
    $recheckDeadline = microtime(true) + max(1, min(300, $seconds)) / 2;
    $lockName = 'icon-worker-' . substr(hash('sha256', $source), 0, 40);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) return ['busy' => true, 'scanned' => 0, 'rechecked' => 0];
    $resolve = $resolver ?? static fn(array $a, bool $force): array => ai_resolve($root, $a, $force);
    $stats = ['busy' => false, 'scanned' => 0, 'rechecked' => 0];
    try {
        $st = $pdo->prepare("INSERT INTO activity_icon_workers (source_key, last_started_at) VALUES (?, UTC_TIMESTAMP(6))
            ON DUPLICATE KEY UPDATE last_started_at = UTC_TIMESTAMP(6), last_error = NULL");
        $st->execute([$source]);
        // Reserve half the batch for new activities so a large recheck queue cannot starve ingestion.
        $recheckLimit = max(1, intdiv($limit, 2));
        $st = $pdo->prepare("SELECT * FROM activity_icon_issues WHERE source_key = ?
            AND (recheck_requested_at IS NOT NULL OR next_check_at <= UTC_TIMESTAMP(6))
            ORDER BY (recheck_requested_at IS NULL), COALESCE(recheck_requested_at, next_check_at), id LIMIT {$recheckLimit}");
        $st->execute([$source]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $issue) {
            if (microtime(true) >= $recheckDeadline) break;
            $activity = ['id' => (int)$issue['sample_activity_id'], 'activity_text' => $issue['sample_text'], 'activity_details' => $issue['sample_details']];
            $started = ii_now();
            $result = $resolve($activity, $issue['recheck_requested_at'] !== null);
            ii_record($pdo, $source, $activity, $result, $started, (int)$issue['id']);
            $stats['rechecked']++;
        }
        $remaining = $limit - $stats['rechecked'];
        $sql = "SELECT a.id, a.activity_text, a.activity_details FROM member_activities a
            LEFT JOIN activity_icon_checks c ON c.source_key = ? AND c.activity_id = a.id
            WHERE c.activity_id IS NULL AND a.activity_text NOT IN ('Rank-up required', 'Rank-up processed')";
        $params = [$source];
        if ($clanId > 0) { $sql .= ' AND a.member_clan_id = ?'; $params[] = $clanId; }
        $st = $pdo->prepare($sql . " ORDER BY a.id ASC LIMIT {$remaining}");
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $activity) {
            if (microtime(true) >= $deadline) break;
            $started = ii_now();
            ii_record($pdo, $source, $activity, $resolve($activity, false), $started);
            $stats['scanned']++;
        }
        $st = $pdo->prepare('UPDATE activity_icon_workers SET last_finished_at = UTC_TIMESTAMP(6), last_error = NULL WHERE source_key = ?');
        $st->execute([$source]);
        return $stats;
    } catch (Throwable $e) {
        $st = $pdo->prepare('UPDATE activity_icon_workers SET last_error = ? WHERE source_key = ?');
        $st->execute([mb_substr($e->getMessage(), 0, 255), $source]);
        throw $e;
    } finally {
        $st = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $st->execute([$lockName]);
    }
}
