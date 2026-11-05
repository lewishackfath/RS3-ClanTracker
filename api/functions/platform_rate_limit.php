<?php
declare(strict_types=1);

// Keep identical in both repositories. One budget for their shared outbound IP.
function rp_rate_limit_schema(PDO $pdo): void
{
    static $ready;
    $ready ??= new WeakMap();
    if (isset($ready[$pdo])) return;
    if ($pdo->inTransaction()) throw new RuntimeException('HTTP requests must run outside database transactions');
    $pdo->exec('CREATE TABLE IF NOT EXISTS platform_http_state (
        id TINYINT PRIMARY KEY,
        next_request_at DECIMAL(20,6) NOT NULL DEFAULT 0,
        cooldown_until DECIMAL(20,6) NOT NULL DEFAULT 0,
        consecutive_429 INT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $pdo->exec('INSERT IGNORE INTO platform_http_state (id) VALUES (1)');
    $ready[$pdo] = true;
}

function rp_rate_limit_response(string $error, int $retryAfter): array
{
    return ['status' => 429, 'body' => json_encode(['error' => $error]),
        'error' => null, 'retry_after' => max(1, $retryAfter), 'headers' => [],
        'locally_throttled' => true];
}

/**
 * Pace all participating workers to at most ten request starts per second.
 * Hold the advisory lock through dispatch and response: a delayed worker cannot
 * redeem an old reservation in a burst, and a 429 is visible before the next send.
 * No network call is allowed if the shared limiter cannot be read or locked.
 */
function rp_rate_limited_request(PDO $pdo, callable $send): array
{
    if ($pdo->inTransaction()) throw new RuntimeException('HTTP requests must run outside database transactions');
    rp_rate_limit_schema($pdo);
    $name = 'rs-platform-http:' . substr(hash('sha256', (string)$pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 2)');
    $lock->execute([$name]);
    if ((int)$lock->fetchColumn() !== 1) return rp_rate_limit_response('CLIENT_RATE_LIMIT_BUSY', 1);
    try {
        $state = $pdo->query('SELECT * FROM platform_http_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!$state) throw new RuntimeException('Shared HTTP limiter state is missing');
        $now = microtime(true);
        if ((float)$state['cooldown_until'] > $now) {
            return rp_rate_limit_response('CLIENT_RATE_LIMIT_COOLDOWN', (int)ceil((float)$state['cooldown_until'] - $now));
        }
        $wait = (float)$state['next_request_at'] - $now;
        // Defer rather than occupy a PHP worker through a clock adjustment.
        if ($wait > 1) return rp_rate_limit_response('CLIENT_RATE_LIMIT_WAIT', (int)ceil($wait));
        while (($wait = (float)$state['next_request_at'] - microtime(true)) > 0) {
            usleep((int)ceil($wait * 1000000));
        }
        $update = $pdo->prepare('UPDATE platform_http_state SET next_request_at = ? WHERE id = 1');
        $update->execute([sprintf('%.6F', microtime(true) + 0.100)]);
        try {
            $response = $send();
        } finally {
            // Base the next slot on completion as well, so slow SQL, scheduling
            // or a delayed dispatch cannot compress the gap between requests.
            $update->execute([sprintf('%.6F', microtime(true) + 0.100)]);
        }
        $status = (int)($response['status'] ?? 0);
        if ($status === 429) {
            $count = min(7, (int)$state['consecutive_429'] + 1);
            $delay = max((int)($response['retry_after'] ?? 0), min(3600, 60 * (2 ** ($count - 1))));
            $update = $pdo->prepare('UPDATE platform_http_state SET cooldown_until = ?, consecutive_429 = ? WHERE id = 1');
            $update->execute([sprintf('%.6F', microtime(true) + $delay), $count]);
            $response['retry_after'] = $delay;
        } elseif (($status >= 200 && $status < 300) || $status === 304) {
            $pdo->exec('UPDATE platform_http_state SET cooldown_until = 0, consecutive_429 = 0 WHERE id = 1');
        }
        return $response;
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$name]);
    }
}
