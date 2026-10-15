<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../api/_db.php';
require_once __DIR__ . '/../api/functions/icon_worker.php';

try {
    $pdo = tracker_pdo(true);
    ii_ensure_schema($pdo);
    if (in_array('--setup-only', $argv, true)) {
        echo "Activity icon tables are ready.\n";
        exit;
    }
    $options = getopt('', ['limit:', 'seconds:']);
    $result = iw_run($pdo, dirname(__DIR__), ii_source(), (int)getenv('TRACKER_CLAN_ID'),
        (int)($options['limit'] ?? 100), (int)($options['seconds'] ?? 45));
    echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Activity icon check failed: ' . $e->getMessage() . "\n");
    exit(1);
}
