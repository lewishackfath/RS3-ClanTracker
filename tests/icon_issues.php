<?php
declare(strict_types=1);

require_once __DIR__ . '/../db_bootstrap.php';
require_once __DIR__ . '/../api/functions/icon_worker.php';

function issue_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$dsn = getenv('ICON_TEST_DSN');
if (!$dsn) throw new RuntimeException('Set ICON_TEST_DSN to a disposable MySQL/MariaDB server');
$pdo = new PDO($dsn, getenv('ICON_TEST_USER') ?: 'root', getenv('ICON_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
]);
$db = 'icon_test_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `$db`");
$pdo->exec("USE `$db`");
try {
    dbb_bootstrap_schema($pdo);
    dbb_bootstrap_schema($pdo);
    $pdo->exec("INSERT INTO clans (name) VALUES ('First clan'), ('Other clan')");
    $pdo->exec("INSERT INTO members (clan_id, rsn, rsn_normalised) VALUES (1, 'Tester', 'tester'), (2, 'Other', 'other')");
    $pdo->exec("INSERT INTO member_activities (member_id, member_clan_id, activity_date_utc, activity_hash, activity_text, activity_details)
        VALUES (1, 1, UTC_TIMESTAMP(), 'one', 'Explored 100 chambers.', 'A first example'),
               (1, 1, UTC_TIMESTAMP(), 'two', 'EXPLORED 100 chambers.', 'A first example'),
               (1, 1, UTC_TIMESTAMP(), 'three', 'I found a New item.', ''),
               (1, 1, UTC_TIMESTAMP(), 'four', 'New unseen activity.', ''),
               (2, 2, UTC_TIMESTAMP(), 'five', 'Other clan activity.', ''),
               (1, 1, UTC_TIMESTAMP(), 'six', 'Rank-up required', '')");
    $activities = $pdo->query('SELECT * FROM member_activities ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $root = dirname(__DIR__);
    $missing = static fn(array $a): array => ai_plan($root, $a) + ['status' => 'missing', 'reason' => 'missing_asset', 'attempts' => []];
    $resolved = static fn(array $a): array => ai_plan($root, $a) + ['status' => 'resolved', 'reason' => '', 'attempts' => []];
    $get = static fn(int $id): array => $pdo->query('SELECT * FROM activity_icon_issues WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
    ii_record($pdo, 'tracker', $activities[0], $missing($activities[0]), '2020-01-01 00:00:01.000000');
    ii_record($pdo, 'tracker', $activities[0], $missing($activities[0]), '2020-01-01 00:00:02.000000');
    ii_record($pdo, 'tracker', $activities[1], $missing($activities[1]), '2020-01-01 00:00:03.000000');
    $id = (int)$pdo->query('SELECT id FROM activity_icon_issues')->fetchColumn();
    issue_check((int)$pdo->query('SELECT COUNT(*) FROM activity_icon_issues')->fetchColumn() === 1, 'One entry per pattern');
    issue_check((int)$get($id)['occurrence_count'] === 2, 'Distinct activity count ignores profile refreshes');
    ii_record($pdo, 'tracker', $activities[0], $resolved($activities[0]), '2020-01-01 00:00:04.000000');
    issue_check($get($id)['status'] === 'resolved' && $get($id)['resolved_at'] !== null, 'Successful detection resolves issue');
    issue_check((int)$get($id)['occurrence_count'] === 2, 'Resolution retains historical count');
    ii_record($pdo, 'tracker', $activities[1], $missing($activities[1]), '2020-01-01 00:00:03.500000');
    issue_check($get($id)['status'] === 'resolved', 'Older slow failure cannot overwrite a newer success');
    ii_record($pdo, 'tracker', $activities[1], $missing($activities[1]), '2020-01-01 00:00:05.000000');
    issue_check($get($id)['status'] === 'open' && $get($id)['resolved_at'] === null, 'Regression reopens same issue');
    issue_check((int)$get($id)['occurrence_count'] === 2, 'Regression of same activity does not inflate count');
    $temporary = $missing($activities[2]);
    $temporary['status'] = 'temporary';
    $temporary['reason'] = 'wiki_http_429';
    ii_record($pdo, 'tracker', $activities[2], $temporary, '2020-01-01 00:00:06.000000');
    $retryId = (int)$pdo->query("SELECT id FROM activity_icon_issues WHERE category = 'item'")->fetchColumn();
    issue_check($get($retryId)['status'] === 'retrying', 'Transient failures are separately labelled for retry');

    ii_record($pdo, 'other-tracker', $activities[0], $missing($activities[0]), ii_now());
    issue_check((int)$pdo->query("SELECT COUNT(*) FROM activity_icon_issues WHERE source_key = 'other-tracker'")->fetchColumn() === 1, 'Separate asset deployments do not resolve each other’s issues');
    issue_check(ii_queue($pdo, $id, 'tracker') === 1, 'Admin queues a single recheck');
    ii_record($pdo, 'tracker', $activities[1], $missing($activities[1]), ii_now());
    issue_check($get($id)['recheck_requested_at'] !== null, 'Ordinary cached feed failure cannot consume a forced admin recheck');
    $forced = [];
    $resolver = static function (array $a, bool $force) use (&$forced, $resolved): array {
        $forced[(int)$a['id']] = $force;
        return $resolved($a);
    };
    $stats = iw_run($pdo, $root, 'tracker', 1, 100, 45, $resolver);
    issue_check($stats['rechecked'] === 2 && $stats['scanned'] === 1, 'Worker rechecks issues and discovers unseen stored activities');
    issue_check($get($id)['status'] === 'resolved' && $get($id)['recheck_requested_at'] === null, 'Queued fix leaves active list');
    issue_check(in_array(true, $forced, true), 'Explicit recheck bypasses negative image cache');
    issue_check((int)$pdo->query("SELECT COUNT(*) FROM activity_icon_checks WHERE source_key = 'tracker'")->fetchColumn() === 4, 'Worker excludes another clan and synthetic rank-up events');
    issue_check((int)$pdo->query("SELECT COUNT(*) FROM activity_icon_issues WHERE source_key = 'tracker' AND status <> 'resolved'")->fetchColumn() === 0, 'Successful unseen icons do not create issue rows');
    $stats = iw_run($pdo, $root, 'tracker', 1, 100, 45, $resolver);
    issue_check($stats['scanned'] === 0 && $stats['rechecked'] === 0, 'Repeated runs respect completed scans and retry deadlines');
    issue_check(ii_queue($pdo, null, 'tracker') === 0, 'Recheck all excludes resolved and other trackers');

    // An admin action arriving after a lookup began must survive that lookup.
    $started = ii_now();
    ii_queue($pdo, $id, 'tracker');
    ii_record($pdo, 'tracker', $activities[0], $resolved($activities[0]), $started);
    issue_check($get($id)['recheck_requested_at'] !== null, 'Concurrent later recheck request is preserved');
    $pdo->exec("DELETE FROM member_activities WHERE id IN (1,2)");
    $stats = iw_run($pdo, $root, 'tracker', 1, 100, 45, $resolver);
    issue_check($stats['rechecked'] === 1 && $get($id)['status'] === 'resolved', 'Saved sample permits rechecks after source activity deletion');
    issue_check((int)$get($id)['occurrence_count'] === 2, 'Deleting source activities preserves issue history');

    $second = new PDO($dsn, getenv('ICON_TEST_USER') ?: 'root', getenv('ICON_TEST_PASSWORD') ?: '');
    $lockName = 'icon-worker-' . substr(hash('sha256', 'tracker'), 0, 40);
    $st = $second->prepare('SELECT GET_LOCK(?, 0)'); $st->execute([$lockName]);
    try { issue_check(iw_run($pdo, $root, 'tracker')['busy'], 'Overlapping workers do not process the same queue'); }
    finally { $st = $second->prepare('SELECT RELEASE_LOCK(?)'); $st->execute([$lockName]); }
    echo "Icon schema, deduplication, resolution, retries, queueing, worker scope and concurrency checks passed.\n";
} finally {
    $pdo->exec("DROP DATABASE `$db`");
}
