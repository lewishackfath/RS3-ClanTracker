<?php
declare(strict_types=1);
$tracker = !is_file(__DIR__ . '/../functions/platform_api.php');
require_once __DIR__ . ($tracker ? '/../api/functions/platform_api.php' : '/../functions/platform_api.php');
function rate_check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function rate_pdo(string $db = ''): PDO {
    $pdo = new PDO(getenv('PLATFORM_TEST_DSN'), getenv('PLATFORM_TEST_USER') ?: 'root', getenv('PLATFORM_TEST_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    if ($db !== '') {
        rate_check((bool)preg_match('/^platform_rate_test_[a-f0-9]+$/D', $db), 'Disposable database name');
        $pdo->exec("USE `$db`");
    }
    return $pdo;
}
if (!getenv('PLATFORM_TEST_DSN')) {
    echo "Rate-limit database/concurrency checks skipped (set PLATFORM_TEST_DSN).\n";
    exit;
}
if (($argv[1] ?? '') === '--worker') {
    $pdo = rate_pdo($argv[2]);
    for ($i = 0; $i < 4; $i++) {
        $result = rp_rate_limited_request($pdo, function () use ($argv): array {
            file_put_contents($argv[4] . '/' . $argv[3], sprintf('%.6F', microtime(true)) . "\n", FILE_APPEND);
            usleep(10000); // Simulate a fast upstream; no RuneScape requests are made.
            return ['status' => 200, 'body' => 'ok'];
        });
        rate_check($result['status'] === 200, 'Concurrent worker received a slot');
    }
    exit;
}
$db = 'platform_rate_test_' . bin2hex(random_bytes(5));
$dir = sys_get_temp_dir() . '/' . $db;
mkdir($dir, 0700);
$pdo = rate_pdo();
$pdo->exec("CREATE DATABASE `$db`");
$pdo->exec("USE `$db`");
$workers = [];
try {
    rp_rate_limit_schema($pdo);
    rp_rate_limit_schema($pdo);
    for ($i = 0; $i < 6; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $db, (string)$i, $dir],
            [0 => ['pipe', 'r'], 1 => ['file', $dir . '/out-' . $i, 'w'], 2 => ['file', $dir . '/err-' . $i, 'w']], $pipes);
        rate_check(is_resource($process), 'Worker started');
        fclose($pipes[0]);
        $workers[$i] = $process;
    }
    foreach ($workers as $i => $process) {
        $exit = proc_close($process); unset($workers[$i]);
        rate_check($exit === 0, 'Worker failed: ' . file_get_contents($dir . '/err-' . $i));
    }
    $times = [];
    for ($i = 0; $i < 6; $i++) {
        foreach (file($dir . '/' . $i, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $time) $times[] = (float)$time;
    }
    sort($times); rate_check(count($times) === 24, 'All worker requests completed');
    foreach ($times as $i => $time) {
        if ($i) rate_check($time - $times[$i - 1] >= 0.100, 'Concurrent requests cannot burst');
        $count = count(array_filter($times, static fn(float $t): bool => $t >= $time && $t < $time + 1));
        rate_check($count <= 10, 'At most ten requests in each rolling second');
    }
    $other = rate_pdo($db);
    $calls = 0;
    $send = function () use (&$calls): array { $calls++; return ['status' => 200, 'body' => 'ok']; };
    $limited = rp_rate_limited_request($pdo, fn() => ['status' => 429, 'body' => '{}', 'retry_after' => 2]);
    rate_check($limited['retry_after'] === 60, 'First global 429 waits at least sixty seconds');
    $blocked = rp_rate_limited_request($other, $send);
    rate_check($calls === 0 && $blocked['locally_throttled'] && $blocked['retry_after'] > 0, 'Cooldown blocks other connections without HTTP');
    rate_check((int)$pdo->query('SELECT consecutive_429 FROM platform_http_state')->fetchColumn() === 1, 'Cached cooldown does not inflate failure count');
    $pdo->exec('UPDATE platform_http_state SET cooldown_until = 0');
    $limited = rp_rate_limited_request($other, fn() => ['status' => 429, 'body' => '{}']);
    rate_check($limited['retry_after'] === 120, 'Repeated global 429 doubles cooldown');
    $pdo->exec('UPDATE platform_http_state SET cooldown_until = 0');
    $limited = rp_rate_limited_request($other, fn() => ['status' => 429, 'body' => '{}', 'retry_after' => 7200]);
    rate_check($limited['retry_after'] === 7200, 'Retry-After can exceed the local backoff cap');
    $pdo->exec('UPDATE platform_http_state SET cooldown_until = 0');
    rp_rate_limited_request($pdo, $send);
    rate_check($calls === 1 && (int)$pdo->query('SELECT consecutive_429 FROM platform_http_state')->fetchColumn() === 0, 'A successful upstream response clears failure count');
    try {
        rp_rate_limited_request($pdo, function (): array { throw new RuntimeException('Simulated transport failure'); });
        throw new RuntimeException('Expected transport failure');
    } catch (RuntimeException $e) {
        rate_check($e->getMessage() === 'Simulated transport failure', 'Transport failure propagates');
    }
    rate_check(rp_rate_limited_request($other, $send)['status'] === 200, 'Failure releases the shared lock');
    $pdo->beginTransaction();
    try {
        rp_rate_limited_request($pdo, $send);
        throw new RuntimeException('Expected active transaction rejection');
    } catch (RuntimeException $e) {
        rate_check(str_contains($e->getMessage(), 'outside database transactions'), 'Reject uncommitted limiter writes');
    } finally { $pdo->rollBack(); }
    rate_check($calls === 2, 'Invalid limiter context makes no request');
    $pdo->exec('DELETE FROM platform_http_state');
    try {
        rp_rate_limited_request($pdo, $send);
        throw new RuntimeException('Expected missing state rejection');
    } catch (RuntimeException $e) {
        rate_check(str_contains($e->getMessage(), 'state is missing'), 'Missing limiter state fails closed');
    }
    rate_check($calls === 2, 'Missing limiter state makes no request');
    echo "PASS: 24 concurrent-worker requests paced below 20/s; shared 429 cooldown, Retry-After, recovery and fail-closed checks.\n";
} finally {
    foreach ($workers as $process) { proc_terminate($process); proc_close($process); }
    $other = null;
    $pdo->exec("DROP DATABASE `$db`");
    foreach (glob($dir . '/*') as $file) unlink($file);
    rmdir($dir);
}
