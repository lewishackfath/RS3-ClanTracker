<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/_db.php';
require_once __DIR__ . '/../db_bootstrap.php';

// Pause all cap/visit processors sharing this database before running.
dbb_migrate_citadel_activity_links(tracker_pdo());
echo "Cap/visit activity links migrated successfully.\n";
