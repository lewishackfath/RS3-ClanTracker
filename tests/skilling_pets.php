<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/functions/skilling_pets.php';
function pet_check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function pet_csv(string $csv, array $definitions): array {
    $handle = fopen('php://temp', 'w+');
    fwrite($handle, $csv);
    rewind($handle);
    try { return sp_parse_csv($handle, $definitions); }
    finally { fclose($handle); }
}
function pet_invalid(string $csv, array $definitions): void {
    try { pet_csv($csv, $definitions); }
    catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Invalid CSV was accepted: ' . $csv);
}
$file = __DIR__ . '/../assets/skilling-pets/pets.json';
$definitions = sp_load_definitions($file);
pet_check(count($definitions['pets']) === 29, 'All 29 supplied pets are configured');
foreach ($definitions['pets'] as $key => $pet) {
    pet_check(sp_match_activity(['activity_text' => 'I found ' . $pet['name'] . '.'], $definitions) === $key, 'Find text matches ' . $key);
    pet_check(sp_match_activity(['activity_details' => 'While skilling, I found ' . $pet['name'] . '.'], $definitions) === $key, 'Details match ' . $key);
}
pet_check(sp_match_activity(['activity_text' => '<b>I FOUND Baby Yaga’s House, the Construction pet.</b>'], $definitions) === 'construction', 'Markup, case and apostrophes normalize');
pet_check(sp_match_activity(['activity_text' => "I found Baby Yaga&#039;s House, the Construction pet."], $definitions) === 'construction', 'HTML entities normalize');
foreach (['I found Gemi.', 'I found Gemi, the Crafting pet token.', 'I found General Awwdor.', 'I have not found Gemi, the Crafting pet.', 'Gemi, the Crafting pet', 'I found Gemi, the Crafting pet. Another sentence.'] as $text) {
    pet_check(sp_match_activity(['activity_text' => $text], $definitions) === null, 'Unrelated text does not match');
}
$future = $definitions;
$future['pets']['new_skill'] = ['key' => 'new_skill', 'skill' => 'New skill', 'name' => 'Future, the New skill pet', 'icon_name' => 'Future (pet)', 'aliases' => ['Future, the New Skill companion']];
pet_check(sp_match_activity(['activity_text' => 'I found Future, the New Skill companion.'], $future) === 'new_skill', 'New skills and aliases need no parser changes');
$csv = fopen('php://temp', 'w+');
sp_write_csv($csv, $definitions, ['crafting' => ['is_collected' => 1]]);
rewind($csv);
$requested = sp_parse_csv($csv, $definitions);
fclose($csv);
pet_check(count($requested) === 29 && $requested['crafting'] && !$requested['construction'], 'CSV round trip handles commas and apostrophes');
pet_check(pet_csv("\xEF\xBB\xBFSkill,Pet,Is collected\r\nCrafting,\"Gemi (pet)\",TRUE\r\n", $definitions) === ['crafting' => true], 'BOM, CRLF and wiki aliases accepted');
foreach ([
    "Pet,Is collected\nGemi,yes\n",
    "Skill,Pet,Is collected\nCrafting,\"Gemi (pet)\",\n",
    "Skill,Pet,Is collected\nMining,\"Gemi (pet)\",yes\n",
    "Skill,Pet,Is collected\nCrafting,\"Unknown\",yes\n",
    "Skill,Pet,Is collected\nCrafting,\"Gemi (pet)\",yes\nCrafting,\"Gemi (pet)\",no\n",
    "Skill,Pet,Is collected,Pet\nCrafting,\"Gemi (pet)\",yes,Rocky\n",
] as $invalid) pet_invalid($invalid, $definitions);
echo "PASS: 29 pet definitions, activity matching, future aliases, CSV round trips and validation\n";

$dsn = getenv('PET_TEST_DSN');
if (!$dsn) { echo "Database tests skipped: set PET_TEST_DSN for a disposable MySQL/MariaDB server.\n"; exit; }
require_once __DIR__ . '/../db_bootstrap.php';
$pdo = new PDO($dsn, getenv('PET_TEST_USER') ?: 'root', getenv('PET_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$db = 'skilling_pets_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `$db`");
$pdo->exec("USE `$db`");
try {
    dbb_bootstrap_schema($pdo);
    dbb_bootstrap_schema($pdo);
    $pdo->exec("INSERT INTO clans (name) VALUES ('First'), ('Second')");
    $pdo->exec("INSERT INTO members (clan_id, rsn, rsn_normalised) VALUES (1, 'Tester', 'tester'), (2, 'Other', 'other')");
    $insert = $pdo->prepare('INSERT INTO member_activities (member_id, member_clan_id, activity_hash, activity_text, activity_details, activity_date_utc) VALUES (?, ?, ?, ?, ?, ?)');
    $insert->execute([1, 1, 'gemi', 'I found Gemi, the Crafting pet.', 'While skilling, I found Gemi, the Crafting pet.', '2020-01-02 00:00:00']);
    $insert->execute([1, 1, 'gemi-again', 'I found Gemi, the Crafting pet.', '', '2020-01-03 00:00:00']);
    $insert->execute([1, 1, 'yaga', '', "While skilling, I found Baby Yaga's House, the Construction pet.", '2020-01-01 00:00:00']);
    $insert->execute([2, 2, 'other', 'I found Rocky, the Mining pet.', '', '2020-01-01 00:00:00']);
    $insert->execute([1, 2, 'other-clan', 'I found Rocky, the Mining pet.', '', '2020-01-01 00:00:00']);
    $insert->execute([1, 1, 'ordinary', 'I found a Bandos helmet.', '', '2020-01-01 00:00:00']);
    $result = sp_sync($pdo, 1, 1, $definitions);
    pet_check($result === ['matched_activities' => 3, 'matched_pets' => 2], 'Text/details counted once; member/clan isolated');
    sp_sync($pdo, 1, 1, $definitions);
    $collection = sp_fetch_collection($pdo, 1, 1);
    pet_check(count($collection) === 2 && $collection['crafting']['first_seen_utc'] === '2020-01-02 00:00:00.000', 'Replay does not duplicate pets and retains earliest date');
    pet_check($collection['crafting']['last_seen_utc'] === '2020-01-03 00:00:00.000', 'Latest detection is retained');
    $log = sp_build_log($pdo, 1, 1, $file);
    pet_check($log['available'] && $log['summary'] === ['found_pets' => 2, 'total_pets' => 29], 'Player payload includes collection summary');
    $member = ['id' => 1, 'clan_id' => 1, 'rsn' => 'Tester'];
    $submitted = sp_submit($pdo, $member, $definitions, ['mining' => true, 'crafting' => false], 'Before tracking');
    pet_check($submitted['changes'] === 2 && count(sp_fetch_collection($pdo, 1, 1)) === 2, 'Public upload stays pending');
    pet_check(sp_review($pdo, $submitted['submission_uuid'], 'accepted', 'Admin', $definitions) === 2, 'Approval applies both additions and removals');
    pet_check(sp_review($pdo, $submitted['submission_uuid'], 'accepted', 'Admin', $definitions) === 0, 'Repeated approval is idempotent');
    sp_sync($pdo, 1, 1, $definitions);
    $collection = sp_fetch_collection($pdo, 1, 1);
    pet_check(!$collection['crafting']['is_collected'] && $collection['mining']['is_collected'], 'Accepted corrections survive inference');
    pet_check($collection['mining']['first_seen_utc'] === null && $collection['mining']['collection_source'] === 'player_csv', 'CSV backfill has provenance and no invented discovery date');
    $declined = sp_submit($pdo, $member, $definitions, ['fishing' => true], '');
    pet_check(sp_review($pdo, $declined['submission_uuid'], 'declined', 'Admin', $definitions) === 1, 'Decline reviews pending rows');
    pet_check(!isset(sp_fetch_collection($pdo, 1, 1)['fishing']), 'Decline leaves collection unchanged');
    $first = sp_submit($pdo, $member, $definitions, ['fishing' => true], '');
    $second = sp_submit($pdo, $member, $definitions, ['fishing' => true], '');
    pet_check(sp_review($pdo, $first['submission_uuid'], 'accepted', 'Admin', $definitions) === 0, 'New upload supersedes previous request');
    $cancel = sp_submit($pdo, $member, $definitions, ['fishing' => false], '');
    pet_check(!$cancel['submitted'] && sp_review($pdo, $second['submission_uuid'], 'accepted', 'Admin', $definitions) === 0, 'No-op upload cancels stale pending request');
    $pending = sp_submit($pdo, $member, $definitions, ['mining' => false], '');
    pet_check(sp_save_collection($pdo, $member, $definitions, ['construction', 'crafting']) === 2, 'Admin can correct both found and missing pets');
    sp_sync($pdo, 1, 1, $definitions);
    $collection = sp_fetch_collection($pdo, 1, 1);
    pet_check(!$collection['mining']['is_collected'] && $collection['crafting']['is_collected'], 'Manual corrections persist');
    pet_check(sp_review($pdo, $pending['submission_uuid'], 'accepted', 'Admin', $definitions) === 0, 'Admin edit supersedes pending change for that pet');
    $atomic = sp_submit($pdo, $member, $definitions, ['fishing' => true, 'agility' => true], '');
    $missingDefinition = $definitions;
    unset($missingDefinition['pets']['agility']);
    try { sp_review($pdo, $atomic['submission_uuid'], 'accepted', 'Admin', $missingDefinition); throw new LogicException('Expected removed definition failure'); }
    catch (RuntimeException $e) { pet_check(!($e instanceof LogicException), 'Review rejects obsolete definitions'); }
    pet_check(!isset(sp_fetch_collection($pdo, 1, 1)['fishing']), 'Failed approval rolls back all pet changes');
    sp_review($pdo, $atomic['submission_uuid'], 'declined', 'Admin', $missingDefinition);
    pet_http_endpoints($dsn, $db);
    $pdo->exec('DELETE FROM member_activities WHERE member_id = 1');
    $collection = sp_fetch_collection($pdo, 1, 1);
    pet_check($collection['crafting']['source_activity_id'] === null && $collection['crafting']['is_collected'], 'Deleting source activity preserves collection');
    $pdo->exec('DELETE FROM members WHERE id = 1');
    pet_check(!$pdo->query('SELECT COUNT(*) FROM member_skilling_pets')->fetchColumn(), 'Member deletion cascades collection');
    pet_check(!$pdo->query('SELECT COUNT(*) FROM member_skilling_pet_submissions')->fetchColumn(), 'Member deletion cascades submissions');
    $pdo->exec('DROP TABLE member_skilling_pets');
    $unavailable = sp_build_log($pdo, 2, 2, $file);
    pet_check(!$unavailable['available'] && count($unavailable['pets']) === 29, 'Missing schema reports unavailability while retaining the catalogue');
    echo "PASS: repeatable schema, history inference, isolation, replay, manual edits, CSV review, supersession, rollback and deletion\n";
} finally {
    $pdo->exec("DROP DATABASE `$db`");
}

// Exercise the actual endpoint dispatch, multipart upload and player response.
function pet_http_endpoints(string $dsn, string $db): void
{
    $host = 'localhost';
    $parts = [];
    foreach (explode(';', substr($dsn, strlen('mysql:'))) as $part) {
        if (str_contains($part, '=')) { [$key, $value] = explode('=', $part, 2); $parts[$key] = $value; }
    }
    $host = $parts['host'] ?? $host;
    foreach (['port', 'unix_socket'] as $key) if (isset($parts[$key])) $host .= ";$key=" . $parts[$key];
    $env = array_merge(getenv(), ['TRACKER_DB_HOST' => $host, 'TRACKER_DB_NAME' => $db,
        'TRACKER_DB_USER' => getenv('PET_TEST_USER') ?: 'root', 'TRACKER_DB_PASS' => getenv('PET_TEST_PASSWORD') ?: '', 'TRACKER_CLAN_ID' => '1']);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    pet_check(is_resource($socket), 'Temporary HTTP port is available');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $log = tempnam(sys_get_temp_dir(), 'pet-http-');
    $csvFile = tempnam(sys_get_temp_dir(), 'pet-csv-');
    $process = proc_open([PHP_BINARY, '-S', $address, '-t', dirname(__DIR__)],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $env);
    pet_check(is_resource($process), 'Temporary HTTP server starts');
    fclose($pipes[0]);
    $request = static function(string $path, ?array $post = null) use ($address): array {
        $ch = curl_init('http://' . $address . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        if ($post !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        $body = curl_exec($ch);
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), $body === false ? '' : $body];
    };
    try {
        for ($i = 0; $i < 50; $i++) {
            [$status] = $request('/api/skilling_pets_csv.php');
            if ($status > 0) break;
            usleep(20000);
        }
        [$status, $body] = $request('/api/player.php?player=Tester&defer_refresh=1');
        $player = json_decode($body, true);
        pet_check($status === 200 && !empty($player['skilling_pets']['available']) && count($player['skilling_pets']['pets']) === 29, 'Real player endpoint exposes skilling pets');
        [$status, $body] = $request('/api/skilling_pets_csv.php?action=download&player=Tester');
        pet_check($status === 200 && str_starts_with($body, 'Skill,Pet,'), 'Real CSV download is CSV');
        file_put_contents($csvFile, $body);
        $post = ['action' => 'upload', 'player' => 'Tester', 'skilling_pets_csv' => new CURLFile($csvFile, 'text/csv', 'pets.csv')];
        [$status, $body] = $request('/api/skilling_pets_csv.php', $post);
        pet_check($status === 200 && json_decode($body, true)['changes'] === 0, 'Unchanged full CSV produces no changes');
        file_put_contents($csvFile, "Skill,Pet,Is collected\nFishing,\"Bubbles (pet)\",yes\n");
        [$status, $body] = $request('/api/skilling_pets_csv.php', $post);
        pet_check($status === 200 && json_decode($body, true)['changes'] === 1, 'Multipart upload creates a pending submission');
        file_put_contents($csvFile, "Skill,Pet,Is collected\nFishing,\"Bubbles (pet)\",yes\nMining,unknown,yes\n");
        [$status, $body] = $request('/api/skilling_pets_csv.php', $post);
        pet_check($status === 400 && str_contains(json_decode($body, true)['error'], 'Line 3'), 'Invalid CSV returns a row-specific error');
        file_put_contents($csvFile, str_repeat('x', 1024 * 1024 + 1));
        [$status] = $request('/api/skilling_pets_csv.php', $post);
        pet_check($status === 400, 'Oversized file is rejected');
        [$status] = $request('/api/skilling_pets_csv.php', ['action' => 'upload', 'player' => 'Tester']);
        pet_check($status === 400, 'Missing upload is rejected');
        [$status] = $request('/api/skilling_pets_csv.php?action=download&player=Other');
        pet_check($status === 404, 'CSV endpoint respects configured clan');
        [$status] = $request('/api/skilling_pets_csv.php?action=download&player=Other&clan=2');
        pet_check($status === 404, 'Explicit clan cannot bypass configured clan scope');
        [$status] = $request('/api/skilling_pets_csv.php?action=upload&player=Tester');
        pet_check($status === 400, 'GET cannot submit corrections');
        echo "PASS: real player/CSV HTTP endpoints, multipart import, no-op export round trip, validation, size limit and clan scope\n";
    } finally {
        proc_terminate($process);
        proc_close($process);
        unlink($log);
        unlink($csvFile);
    }
}
