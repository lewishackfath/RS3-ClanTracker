<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../api/_db.php';
require_once __DIR__ . '/../api/functions/skilling_pets.php';

$pdo = tracker_pdo(true);
sp_ensure_schema($pdo);
if (in_array('--setup-only', $argv, true)) {
    echo "Skilling pets schema ready.\n";
    exit;
}
$definitions = sp_load_definitions(__DIR__ . '/../assets/skilling-pets/pets.json');
$clanId = (int)(getenv('TRACKER_CLAN_ID') ?: 0);
$stmt = $pdo->prepare('SELECT id, clan_id FROM members' . ($clanId > 0 ? ' WHERE clan_id = ?' : '') . ' ORDER BY id');
$stmt->execute($clanId > 0 ? [$clanId] : []);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pets = 0;
foreach ($members as $member) {
    $result = sp_sync($pdo, (int)$member['id'], (int)$member['clan_id'], $definitions);
    $pets += $result['matched_pets'];
}
echo count($members) . " members scanned; {$pets} skilling pets matched.\n";
