<?php
declare(strict_types=1);

// Run real endpoints in child processes; never pull from RuneScape.
if (($argv[1] ?? '') === '--endpoint') {
    $_GET = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);
    register_shutdown_function(static function(): void {
        fwrite(STDERR, 'HTTP_STATUS=' . (http_response_code() ?: 200));
    });
    require __DIR__ . '/../api/' . $argv[2] . '.php';
    exit;
}
require_once __DIR__ . '/../db_bootstrap.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function endpoint(string $name, array $params, array $env, int $status = 200): array {
    $process = proc_open([PHP_BINARY, __FILE__, '--endpoint', $name, json_encode($params)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    check(is_resource($process), 'Endpoint starts');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0, "$name failed: $errors");
    check($errors === "HTTP_STATUS=$status", "$name status/error: $errors $output");
    $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    check($data['ok'] === ($status === 200), "$name response: $output");
    return $data;
}

$dsn = getenv('XP_TEST_DSN');
if (!$dsn) throw new RuntimeException('Set XP_TEST_DSN to a disposable MySQL/MariaDB server');
$user = getenv('XP_TEST_USER') ?: 'root';
$password = getenv('XP_TEST_PASSWORD') ?: '';
$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db = 'custom_xp_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `$db`");
$pdo->exec("USE `$db`");
try {
    dbb_create_tables($pdo);
    $pdo->exec("INSERT INTO clans (name, timezone, reset_weekday) VALUES ('Test clan', 'Australia/Sydney', 1)");
    $insertMember = $pdo->prepare('INSERT INTO members (clan_id, rsn, rsn_normalised, rank_name) VALUES (1, ?, ?, ?)');
    $members = [];
    foreach (['Lodo', 'New member', 'Busy member', 'Future member', 'Unchanged member', 'Guest member'] as $name) {
        $insertMember->execute([$name, strtolower($name), $name === 'Guest member' ? 'guest' : 'Recruit']);
        $members[$name] = (int)$pdo->lastInsertId();
    }
    $insert = $pdo->prepare('INSERT INTO member_xp_snapshots (member_id, total_xp, skills_json, snapshot_hash, captured_at_utc) VALUES (?, ?, ?, ?, ?)');
    $snapshot = static function(string $name, string $at, int $xp) use ($insert, $members): void {
        $skills = json_encode(['Attack' => ['level' => 1, 'xp' => $xp]]);
        $insert->execute([$members[$name], $xp, $skills, hash('sha256', "$at|$xp"), $at]);
    };
    $snapshot('Lodo', '2026-10-03 13:59:59', 100);
    $snapshot('Lodo', '2026-10-03 15:00:00', 150);
    $snapshot('Lodo', '2026-10-04 12:59:59.999', 300);
    $snapshot('Lodo', '2026-10-04 13:00:00', 900); // Next local day: exclude.
    $snapshot('New member', '2026-10-03 15:00:00', 50);
    $snapshot('New member', '2026-10-04 10:00:00', 100);
    $snapshot('Future member', '2026-10-04 13:00:00', 500);
    $snapshot('Unchanged member', '2026-10-03 13:00:00', 80);
    $snapshot('Guest member', '2026-10-03 13:00:00', 100);
    $snapshot('Guest member', '2026-10-04 10:00:00', 5000);
    $snapshot('Busy member', '2026-10-03 14:00:00', 1000); // Exact start baseline.
    $start = new DateTimeImmutable('2026-10-03 14:00:00', new DateTimeZone('UTC'));
    for ($i = 1; $i <= 510; $i++) $snapshot('Busy member', $start->modify("+$i seconds")->format('Y-m-d H:i:s'), 1000 + $i);
    $snapshot('Busy member', '2026-10-04 12:59:59.999', 2000);

    $connection = [];
    foreach (explode(';', substr($dsn, strlen('mysql:'))) as $part) {
        if (str_contains($part, '=')) { [$key, $value] = explode('=', $part, 2); $connection[$key] = $value; }
    }
    $host = $connection['host'] ?? 'localhost';
    foreach (['port', 'unix_socket'] as $key) if (isset($connection[$key])) $host .= ";$key=" . $connection[$key];
    $env = array_merge(getenv(), ['TRACKER_DB_HOST' => $host, 'TRACKER_DB_NAME' => $db, 'TRACKER_DB_USER' => $user, 'TRACKER_DB_PASS' => $password]);
    $params = ['clan' => 1, 'period' => 'custom', 'start_date' => '2026-10-04', 'end_date' => '2026-10-04', 'defer_refresh' => '1'];
    $clan = endpoint('clan', $params, $env);
    check($clan['xp']['start_utc'] === '2026-10-03 14:00:00' && $clan['xp']['end_utc'] === '2026-10-04 12:59:59.999', 'Inclusive DST day');
    $totals = array_column($clan['xp']['totals_by_skill'], null, 'skill');
    check($totals['Total']['gained_xp'] === 1250, 'Clan total uses selected dates and excludes guests');
    check(in_array('custom', array_column($clan['xp_periods'], 'value'), true), 'Clan advertises custom period');
    $top = endpoint('clan', $params + ['skill' => 'attack'], $env);
    check($top['top_earners'][0]['rsn'] === 'Busy member' && $top['top_earners'][0]['gained_xp'] === 1000, 'Skill leaderboard uses custom window');
    foreach (['Lodo' => 200, 'New member' => 50, 'Busy member' => 1000, 'Unchanged member' => 0] as $name => $gain) {
        $player = endpoint('player', $params + ['player' => $name], $env);
        check($player['xp']['gained_total_xp'] === $gain, "$name summary gain");
        check($player['xp_stats']['gained_total_xp'] === $gain, "$name graph gain, including ranges over 500 snapshots");
        check(in_array('custom', array_column($player['xp_periods'], 'value'), true), 'Player advertises custom period');
    }
    $future = endpoint('player', $params + ['player' => 'Future member'], $env);
    check(!$future['xp']['has_data'] && !$future['xp_stats']['has_data'], 'No snapshots inside/before range');
    foreach (['clan', 'player'] as $endpoint) {
        foreach ([['', ''], ['2026-02-29', '2026-03-01'], ['2026-10-05', '2026-10-04']] as [$startDate, $endDate]) {
            endpoint($endpoint, array_replace($params, ['player' => 'Lodo', 'start_date' => $startDate, 'end_date' => $endDate]), $env, 400);
        }
        $preset = endpoint($endpoint, array_replace($params, ['player' => 'Lodo', 'period' => 'alltime', 'start_date' => 'invalid']), $env);
        check($preset['xp']['period'] === 'alltime', 'Preset ignores custom fields');
    }
    echo "PASS: custom ranges through clan/player APIs, inclusive dates, baselines, leaderboards, long histories and invalid inputs\n";
} finally {
    $pdo->exec("DROP DATABASE `$db`");
}
