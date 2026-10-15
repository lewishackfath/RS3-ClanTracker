<?php
declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/functions/activity_icons.php';
require_once __DIR__ . '/functions/icon_issues.php';

// Stored activity IDs are the only input; clients cannot invent issue records,
// filesystem paths or remote fetch URLs. Images are revalidated on every view.
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$root = dirname(__DIR__);
$file = $root . '/assets/activity/default.png';
$status = 'fallback';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) { http_response_code(400); exit; }
try {
    $pdo = tracker_pdo(true);
    $sql = "SELECT id, activity_text, activity_details FROM member_activities WHERE id = ?
        AND activity_text NOT IN ('Rank-up required', 'Rank-up processed')";
    $params = [$id];
    $clanId = (int)getenv('TRACKER_CLAN_ID');
    if ($clanId > 0) { $sql .= ' AND member_clan_id = ?'; $params[] = $clanId; }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $activity = $st->fetch(PDO::FETCH_ASSOC);
    if (!$activity) { http_response_code(404); exit; }
    $started = ii_now();
    $result = ai_resolve($root, $activity);
    if ($result['status'] === 'resolved') { $file = $result['file']; $status = 'resolved'; }
    try {
        ii_record($pdo, ii_source(), $activity, $result, $started);
    } catch (Throwable $e) {
        // Logging is best-effort. Missing migrations/DB errors must not hide a valid image.
        error_log('[activity-icons] Could not record icon result: ' . $e->getMessage());
    }
} catch (Throwable $e) {
    error_log('[activity-icons] Resolution failed: ' . $e->getMessage());
}
header('X-Icon-Status: ' . $status);
if (!is_file($file)) { http_response_code(404); exit; }
header('Content-Type: ' . icon_mime_type($file));
readfile($file);
