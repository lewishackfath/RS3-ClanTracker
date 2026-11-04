<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../api/_db.php';
require_once __DIR__ . '/../api/functions/platform_storage.php';
rp_ensure_schema(tracker_pdo(true));
echo "Platform API schema and polling-state migration complete.\n";
