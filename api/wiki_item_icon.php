<?php
declare(strict_types=1);

require_once __DIR__ . '/functions/wiki_icons.php';
require_once __DIR__ . '/functions/icon_files.php';
header('X-Content-Type-Options: nosniff');
$item = isset($_GET['item']) && is_string($_GET['item']) ? trim($_GET['item']) : '';
if ($item === '') { http_response_code(400); exit; }
$result = wi_resolve(dirname(__DIR__), $item);
if ($result['status'] !== 'resolved') {
    header('Cache-Control: no-store');
    http_response_code($result['status'] === 'temporary' ? 503 : 404);
    exit;
}
serve_icon($result['file']);
