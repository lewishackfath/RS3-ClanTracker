<?php
declare(strict_types=1);

// Endpoint requests run in a separate process because tracker_json() exits.
if (($argv[1] ?? '') === '--endpoint') {
    $_GET = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);
    require __DIR__ . '/../api/' . $argv[2] . '.php';
    exit;
}

require_once __DIR__ . '/../db_bootstrap.php';
require_once __DIR__ . '/../api/functions/runemetrics.php';
require_once __DIR__ . '/../api/functions/process_activities.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function endpoint(string $name, array $params, array $env): array {
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--endpoint', $name, json_encode($params)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, null, $env
    );
    check(is_resource($process), 'Endpoint process starts');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, "$name endpoint failed: $errors");
    $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    check(!empty($data['ok']), "$name endpoint failed: $output");
    return $data;
}

$dsn = getenv('CITADEL_TEST_DSN');
if (!$dsn) throw new RuntimeException('Set CITADEL_TEST_DSN to a disposable MySQL/MariaDB server');
$user = getenv('CITADEL_TEST_USER') ?: 'root';
$password = getenv('CITADEL_TEST_PASSWORD') ?: '';
$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$db = 'citadel_guests_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `$db`");
$pdo->exec("USE `$db`");

try {
    dbb_create_tables($pdo);
    $pdo->exec("INSERT INTO clans (name, timezone, reset_weekday) VALUES ('Test clan', 'UTC', 1), ('Guest-only clan', 'UTC', 1)");
    $pdo->exec("INSERT INTO activity_announcement_rules (purpose, match_kind, match_value) VALUES
        ('cap_detection', 'text_equals', 'Capped'),
        ('visit_detection', 'text_equals', 'Visited'),
        ('announcement', 'text_equals', 'Levelled')");

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $at = $now->format('Y-m-d H:i:s');
    $sourceDate = $now->setTimezone(new DateTimeZone('Europe/London'))->format('d-M-Y H:i:s');
    $activities = array_map(static fn($text) => ['text' => $text, 'date' => $sourceDate], ['Capped', 'Visited', 'Levelled']);
    $tables = ['member_caps', 'member_citadel_visits'];
    $insertMember = $pdo->prepare('INSERT INTO members (clan_id, rsn, rsn_normalised, rank_name) VALUES (1, ?, ?, ?)');
    $insertActivity = $pdo->prepare('INSERT INTO member_activities (member_id, member_clan_id, activity_hash, activity_date_utc, activity_text) VALUES (?, 1, ?, ?, ?)');

    foreach (['Guest', 'guest', ' GuEsT ', 'Recruit', null] as $i => $rank) {
        $guest = $i < 3;
        foreach (['live', 'catchup'] as $mode) {
            $name = "$mode $i";
            $insertMember->execute([$name, $name, $rank]);
            $memberId = (int)$pdo->lastInsertId();
            if ($mode === 'live') {
                $cap = true;
                $week = 'stale';
                check(rm_db_insert_activities_with_processing($pdo, $memberId, 1, $activities, $cap, $week) === 3, 'Raw activities retained');
                check($cap === !$guest && ($week !== null) === !$guest, 'Only members signal cap credit');
                // Also replay a previously queued guest event.
                if ($guest) $pdo->exec("UPDATE member_activities SET is_announced = 0 WHERE member_id = $memberId");
                check(rm_db_insert_activities_with_processing($pdo, $memberId, 1, $activities, $cap, $week) === 0, 'Replay does not add activities');
                check(!$cap && $week === null, 'Replay does not signal new cap credit');
            } else {
                foreach (['Capped', 'Visited', 'Levelled'] as $text) {
                    $insertActivity->execute([$memberId, hash('sha256', "$memberId|$text"), $at, $text]);
                }
                $result = process_activities_for_clan($pdo, 1);
                check($result['ok'] && $result['updated_rule_id'] === 3, 'Catch-up consumes matched activities');
                check($result['caps_upserted'] === (int)!$guest && $result['visits_upserted'] === (int)!$guest, 'Catch-up credits only members');
                check(process_activities_for_clan($pdo, 1)['fetched'] === 0, 'Guest activities do not clog catch-up queue');
            }
            foreach ($tables as $table) {
                check((int)$pdo->query("SELECT COUNT(*) FROM $table WHERE member_id = $memberId")->fetchColumn() === (int)!$guest, 'Only members have cap/visit rows');
            }
            $rows = $pdo->query("SELECT activity_text, rule_id, is_announced, announced_at FROM member_activities WHERE member_id = $memberId")->fetchAll(PDO::FETCH_ASSOC);
            check(count($rows) === 3, 'Other activities retained');
            foreach ($rows as $row) {
                check($row['rule_id'] !== null, 'Activity classification retained');
                $suppressed = $guest && $row['activity_text'] !== 'Levelled';
                check((int)$row['is_announced'] === (int)$suppressed && $row['announced_at'] === null, 'Only guest citadel announcements suppressed');
            }
            if ($guest) {
                detect_and_notify_rank_up($pdo, ['id' => 1], ['id' => $memberId, 'rsn' => $name, 'rank_name' => $rank], $at, [$rank, 'Recruit'], '');
                check((int)$pdo->query("SELECT COUNT(*) FROM member_activities WHERE member_id = $memberId")->fetchColumn() === 3, 'Guests cannot trigger rank-up markers');
            }
        }
    }

    // Legacy guest records must be hidden without deleting legitimate history.
    $pdo->exec('DELETE FROM members');
    $ids = [];
    foreach (['Capped member' => 'Recruit', 'Uncapped member' => 'Corporal', 'Guest one' => 'Guest', 'Guest two' => ' gUeSt ', 'Former member' => 'Recruit'] as $name => $rank) {
        $insertMember->execute([$name, strtolower($name), $rank]);
        $ids[$name] = (int)$pdo->lastInsertId();
    }
    $pdo->exec('UPDATE members SET is_active = 0 WHERE id = ' . $ids['Former member']);
    [$start, $end] = ah_cap_week_bounds_utc($now, 'UTC', 1, '00:00:00');
    foreach (['member_caps' => 'capped_at_utc', 'member_citadel_visits' => 'visited_at_utc'] as $table => $column) {
        $insert = $pdo->prepare("INSERT INTO $table (clan_id, member_id, cap_week_start_utc, cap_week_end_utc, $column) VALUES (1, ?, ?, ?, ?)");
        foreach ($ids as $name => $id) {
            if ($name !== 'Uncapped member') $insert->execute([$id, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $at]);
        }
    }
    $pdo->exec("INSERT INTO members (clan_id, rsn, rsn_normalised, rank_name) VALUES (2, 'Guest only', 'guest only', 'Guest')");

    // Pass only this disposable database to real API entry points.
    $connection = [];
    foreach (explode(';', substr($dsn, strlen('mysql:'))) as $part) {
        if (str_contains($part, '=')) {
            [$key, $value] = explode('=', $part, 2);
            $connection[$key] = $value;
        }
    }
    $host = $connection['host'] ?? 'localhost';
    foreach (['port', 'unix_socket'] as $key) {
        if (isset($connection[$key])) $host .= ";$key=" . $connection[$key];
    }
    $env = array_merge(getenv(), ['TRACKER_DB_HOST' => $host, 'TRACKER_DB_NAME' => $db, 'TRACKER_DB_USER' => $user, 'TRACKER_DB_PASS' => $password]);

    $clan = endpoint('clan', ['clan' => 1], $env);
    check($clan['stats']['active_members'] === 4 && count($clan['members']) === 4, 'Guests remain available in roster');
    check($clan['stats']['citadel_eligible_members'] === 2 && $clan['stats']['capped'] === 1 && $clan['stats']['uncapped'] === 1 && $clan['stats']['percent_capped'] === 50, 'Guests excluded from both sides of capping percentage');
    foreach ($clan['members'] as $member) {
        if (str_starts_with($member['rsn'], 'Guest')) check(!$member['citadel_eligible'] && !$member['capped'] && !$member['visited'], 'Legacy guest credit hidden in roster');
    }
    $guestOnly = endpoint('clan', ['clan' => 2], $env);
    check($guestOnly['stats']['capped'] === 0 && $guestOnly['stats']['uncapped'] === 0 && $guestOnly['stats']['percent_capped'] === 0, 'Guest-only clan has no capping denominator');

    $history = endpoint('cap_history', ['clan' => 1], $env);
    check($history['stats']['active_members'] === 2 && $history['stats']['total_caps'] === 1 && $history['stats']['total_visits'] === 1, 'History excludes guest records');
    check(array_sum(array_column($history['citadel_per_week_1y'], 'cap_count')) === 2 && array_sum(array_column($history['citadel_per_week_1y'], 'visit_count')) === 2, 'Chart excludes guests but preserves former-member history');

    foreach (['Guest one', 'Guest two', 'Capped member'] as $name) {
        $player = endpoint('player', ['clan' => 1, 'player' => $name, 'defer_refresh' => '1'], $env);
        $eligible = $name === 'Capped member';
        check($player['member']['citadel_eligible'] === $eligible && $player['cap']['capped'] === $eligible && $player['visit']['visited'] === $eligible, 'Player credit reflects guest eligibility');
        if (!$eligible) check($player['cap']['capped_at_utc'] === null && $player['visit']['visited_at_utc'] === null, 'Guest dates are hidden');
    }
    foreach ($tables as $table) check((int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() === 4, 'Read filtering preserves stored history');
    echo "PASS: guest live/catch-up processing, replay, announcements, rank-up guard, roster, player and historical totals\n";
} finally {
    $pdo->exec("DROP DATABASE `$db`");
}
