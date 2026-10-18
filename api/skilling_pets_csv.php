<?php
declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/functions/skilling_pets.php';

$handle = null;
$tmp = null;
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $params = $method === 'POST' ? $_POST : $_GET;
    $action = (string)($params['action'] ?? '');
    if (!(($method === 'GET' && $action === 'download') || ($method === 'POST' && $action === 'upload'))) {
        tracker_json(['ok' => false, 'error' => 'Unsupported skilling pets CSV action.'], 400);
    }
    $player = trim((string)($params['player'] ?? ''));
    if ($player === '' || mb_strlen($player) > 64) tracker_json(['ok' => false, 'error' => 'Invalid player.'], 400);
    $pdo = tracker_pdo();
    $configuredClanId = (int)(getenv('TRACKER_CLAN_ID') ?: 0);
    $requestedClanId = (int)($params['clan'] ?? 0);
    if ($configuredClanId > 0 && $requestedClanId > 0 && $requestedClanId !== $configuredClanId) {
        tracker_json(['ok' => false, 'error' => 'Player not found.'], 404);
    }
    $clanId = $configuredClanId ?: $requestedClanId;
    $stmt = $pdo->prepare("SELECT m.id, m.clan_id, m.rsn FROM members m JOIN clans c ON c.id = m.clan_id
        WHERE c.is_enabled = 1 AND c.inactive_at IS NULL
          AND REPLACE(m.rsn_normalised, ' ', '') = ?" . ($clanId > 0 ? ' AND m.clan_id = ?' : '') . '
        ORDER BY m.is_active DESC, m.updated_at DESC, m.id DESC LIMIT 1');
    $bind = [str_replace(' ', '', tracker_normalise($player))];
    if ($clanId > 0) $bind[] = $clanId;
    $stmt->execute($bind);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) tracker_json(['ok' => false, 'error' => 'Player not found.'], 404);
    $definitions = sp_load_definitions(__DIR__ . '/../assets/skilling-pets/pets.json');
    sp_sync($pdo, (int)$member['id'], (int)$member['clan_id'], $definitions);

    if ($action === 'download') {
        $collection = sp_fetch_collection($pdo, (int)$member['id'], (int)$member['clan_id']);
        $filename = preg_replace('/[^a-z0-9_\-]+/i', '_', $member['rsn']) ?: 'player';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '_skilling_pets.csv"');
        header('Cache-Control: no-store');
        $handle = fopen('php://output', 'wb');
        sp_write_csv($handle, $definitions, $collection);
        fclose($handle);
        exit;
    }

    $file = $_FILES['skilling_pets_csv'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Choose a CSV file to upload (maximum 1 MB).');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) throw new InvalidArgumentException('Invalid CSV upload.');
    if (filesize($tmp) > 1024 * 1024) throw new InvalidArgumentException('CSV file is too large. Maximum size is 1 MB.');
    $handle = fopen($tmp, 'rb');
    if (!$handle) throw new InvalidArgumentException('Could not read the CSV file.');
    $requested = sp_parse_csv($handle, $definitions);
    fclose($handle);
    $handle = null;
    unlink($tmp);
    $tmp = null;
    $result = sp_submit($pdo, $member, $definitions, $requested, (string)($_POST['submitter_note'] ?? ''));
    tracker_json(['ok' => true] + $result);
} catch (Throwable $e) {
    if (is_resource($handle)) fclose($handle);
    if ($tmp && is_uploaded_file($tmp)) unlink($tmp);
    $invalid = $e instanceof InvalidArgumentException;
    tracker_json(['ok' => false, 'error' => $invalid ? $e->getMessage() : 'Skilling pets CSV request failed. Check that the updated database bootstrap has been run.'], $invalid ? 400 : 500);
}
