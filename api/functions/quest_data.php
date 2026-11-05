<?php
declare(strict_types=1);
require_once __DIR__ . '/platform_api.php';
require_once __DIR__ . '/../_db.php';

// Compatibility helper for callers outside player.php. Player views use the shared cache.
function get_quest_data(string $rsn): array {
    $result = rp_request('quests', $rsn, null, tracker_pdo(true));
    return array_merge($result, ['rsn' => $rsn, 'hint' => $result['message']]);
}
