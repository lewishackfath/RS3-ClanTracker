<?php
declare(strict_types=1);

// Uses a temporary database, created and dropped by this test. The DSN must
// point to a disposable local MySQL/MariaDB server with CREATE DATABASE access.
require_once __DIR__ . '/../db_bootstrap.php';
require_once __DIR__ . '/../api/functions/runemetrics.php';
require_once __DIR__ . '/../api/functions/process_activities.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$dsn = getenv('CITADEL_TEST_DSN');
if (!$dsn) throw new RuntimeException('Set CITADEL_TEST_DSN to a disposable MySQL/MariaDB server');
$pdo = new PDO($dsn, getenv('CITADEL_TEST_USER') ?: 'root', getenv('CITADEL_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$db = 'citadel_test_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `$db`");
$pdo->exec("USE `$db`");
try {
    dbb_create_tables($pdo);
    $pdo->exec("INSERT INTO clans (name, reset_weekday) VALUES ('Test clan', 1)");
    $pdo->exec("INSERT INTO members (clan_id, rsn, rsn_normalised) VALUES (1, 'Test member', 'test member')");
    $pdo->exec("INSERT INTO activity_announcement_rules (purpose, match_kind, match_value)
        VALUES ('cap_detection', 'text_equals', 'Capped'), ('visit_detection', 'text_equals', 'Visited')");
    $activities = [
        ['text' => 'Capped', 'date' => '2026-09-16 12:00:00'],
        ['text' => 'Visited', 'date' => '2026-09-16 13:00:00'],
    ];
    $cap = false;
    $week = null;
    check(rm_db_insert_activities_with_processing($pdo, 1, 1, $activities, $cap, $week) === 2, 'Initial activity insert');
    check($cap, 'Initial cap signal');
    $tables = ['member_caps' => ['cap', 'capped_at_utc'], 'member_citadel_visits' => ['visit', 'visited_at_utc']];
    $original = [];
    foreach ($tables as $table => $_) {
        $original[$table] = $pdo->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
        check(count($original[$table]) === 1 && $original[$table][0]['activity_id'] !== null, 'Initial activity link');
    }
    $pdo->exec("UPDATE clans SET reset_weekday = 2, reset_time = '04:00:00'");
    check(rm_db_insert_activities_with_processing($pdo, 1, 1, $activities, $cap, $week) === 0, 'Repeated activities reuse persisted IDs');
    check(!$cap && $week === null, 'Reset change must not signal a newly recorded cap');
    $pdo->exec('UPDATE member_activities SET rule_id = NULL');
    $result = process_activities_for_clan($pdo, 1);
    check($result['ok'] && $result['updated_rule_id'] === 2, 'Catch-up processor succeeds');
    foreach ($tables as $table => $_) {
        check($pdo->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC) === $original[$table], 'Replay preserves original row and week');
    }

    // Distinct events in one week must not replace the original source link.
    $pdo->exec("UPDATE clans SET reset_weekday = 1, reset_time = '00:00:00'");
    $sameWeek = [
        ['text' => 'Capped', 'date' => '2026-09-17 12:00:00'],
        ['text' => 'Visited', 'date' => '2026-09-17 13:00:00'],
    ];
    rm_db_insert_activities_with_processing($pdo, 1, 1, $sameWeek, $cap, $week);
    foreach ($tables as $table => $_) {
        check($pdo->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC) === $original[$table], 'Weekly uniqueness preserves source link');
    }
    $pdo->exec("UPDATE clans SET reset_weekday = 2, reset_time = '04:00:00'");

    // Simulate pre-migration tables containing reset-change duplicates and an
    // unmatched historical row. The migration must keep that unmatched row.
    foreach ($tables as $table => [$short, $timestamp]) {
        $pdo->exec("ALTER TABLE $table DROP FOREIGN KEY fk_{$short}_activity, DROP INDEX uk_{$short}_activity, DROP COLUMN activity_id");
        $pdo->exec("INSERT INTO $table (clan_id, member_id, cap_week_start_utc, cap_week_end_utc, $timestamp)
            SELECT clan_id, member_id, DATE_ADD(cap_week_start_utc, INTERVAL 1 HOUR), cap_week_end_utc, $timestamp FROM $table");
        $pdo->exec("INSERT INTO $table (clan_id, member_id, cap_week_start_utc, cap_week_end_utc, $timestamp)
            VALUES (1, 1, '2020-01-01', '2020-01-08', '2020-01-02')");
    }
    dbb_migrate_citadel_activity_links($pdo);
    dbb_migrate_citadel_activity_links($pdo);
    foreach ($tables as $table => $_) {
        check((int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() === 2, 'Migration removes only linked duplicates');
        check((int)$pdo->query("SELECT COUNT(*) FROM $table WHERE activity_id IS NULL")->fetchColumn() === 1, 'Unmatched history remains nullable');
    }
    rm_db_insert_activities_with_processing($pdo, 1, 1, $activities, $cap, $week);
    foreach ($tables as $table => $_) {
        check((int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() === 2, 'Migrated activity cannot duplicate on replay');
    }

    // Another week still accepts new cap/visit activities.
    foreach ($activities as &$activity) $activity['date'] = '2026-09-23 12:00:00';
    unset($activity);
    rm_db_insert_activities_with_processing($pdo, 1, 1, $activities, $cap, $week);
    foreach ($tables as $table => $_) {
        check((int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() === 3, 'New-week activity accepted');
    }
    // New unruled activities exercise catch-up insertion, not just replay.
    $pdo->exec("INSERT INTO members (clan_id, rsn, rsn_normalised) VALUES (1, 'Backfill', 'backfill')");
    $pdo->exec("INSERT INTO member_activities (member_id, member_clan_id, activity_hash, activity_date_utc, activity_text)
        VALUES (2, 1, 'backfill-cap', '2026-09-16 12:00:00', 'Capped'),
               (2, 1, 'backfill-visit', '2026-09-16 13:00:00', 'Visited')");
    check(process_activities_for_clan($pdo, 1)['ok'], 'Catch-up inserts new records');
    foreach ($tables as $table => $_) {
        check((int)$pdo->query("SELECT COUNT(*) FROM $table WHERE member_id = 2 AND activity_id IS NOT NULL")->fetchColumn() === 1, 'Catch-up stores source ID');
    }
    $pdo->exec('DELETE FROM members WHERE id = 2');
    $pdo->exec('DELETE FROM member_activities');
    foreach ($tables as $table => $_) {
        check((int)$pdo->query("SELECT COUNT(*) FROM $table WHERE activity_id IS NULL")->fetchColumn() === 3, 'Deleting source preserves cap/visit history');
    }
    echo "PASS: reset changes, replay, catch-up, migration, reruns, new weeks and source deletion\n";
} finally {
    $pdo->exec("DROP DATABASE `$db`");
}
