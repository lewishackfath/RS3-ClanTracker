<?php
declare(strict_types=1);
require_once __DIR__ . '/platform_rate_limit.php';

// Keep this file identical in RS3-API/functions and ClanTracker/api/functions.
// Boundary adapters preserve the application's existing names and response shapes.
function rp_normalise_name(string $name): string
{
    $name = str_replace(["\xC2\xA0", "\xA0"], ' ', $name);
    $name = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00AD}]/u', '', $name) ?? $name;
    return strtolower(trim(preg_replace('/[\s_]+/u', ' ', $name) ?? $name));
}

function rp_url(string $source, string $rsn): string
{
    $name = rawurlencode(str_replace(' ', '_', trim($rsn)));
    return match ($source) {
        'events' => 'https://secure.runescape.com/m=adventurers-log/api/v1/playerEvents/' . $name,
        'skills' => 'https://secure.runescape.com/m=adventurers-log/api/v1/playerData/' . $name . '?includeSkills=true',
        'quests' => 'https://secure.runescape.com/m=adventurers-log/api/v1/playerQuests/' . $name,
        'hiscores' => 'https://secure.runescape.com/m=hiscore/index_lite.json?player=' . $name,
        default => throw new InvalidArgumentException('Unknown Platform API source'),
    };
}

/** Public contact details can include email, Discord and a contact URL together. */
function rp_user_agent(): string
{
    $contact = getenv('RS_PLATFORM_CONTACT');
    $contact = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $contact === false ? '' : $contact) ?? '');
    if ($contact === '') {
        $contact = 'email: support@hitmedia.com.au; Discord: @lodovicus1993; https://hitmedia.com.au';
    }
    // Escape comment delimiters and prevent configured text from adding headers.
    $contact = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $contact);
    return 'RS3-ClanTracker/2.0 (+https://rs3-api.com.au' . ($contact !== '' ? '; ' . $contact : '') . ')';
}

function rp_http_get(string $url, PDO $pdo, array $headers = ['Accept: application/json'], int $timeout = 15): array
{
    if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'secure.runescape.com') {
        throw new InvalidArgumentException('Unsupported RuneScape API URL');
    }
    return rp_rate_limited_request($pdo, static function () use ($url, $headers, $timeout): array {
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Unable to initialise HTTP request');
        $retryAfter = 0; $responseHeaders = [];
        curl_setopt_array($ch, [
            // Redirects must not make additional unmetered requests.
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout), CURLOPT_TIMEOUT => $timeout, CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => rp_user_agent(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$retryAfter, &$responseHeaders): int {
                if (str_starts_with($header, 'HTTP/')) { $retryAfter = 0; $responseHeaders = []; }
                if (str_contains($header, ':')) {
                    [$name, $value] = explode(':', $header, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                if (stripos($header, 'Retry-After:') === 0) {
                    $value = trim(substr($header, 12));
                    $retryAfter = ctype_digit($value) ? (int)$value : max(0, (strtotime($value) ?: time()) - time());
                }
                return strlen($header);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        return ['status' => $status, 'body' => $body === false ? '' : $body,
            'error' => $body === false ? ($error ?: 'Request failed') : null, 'retry_after' => $retryAfter,
            'headers' => $responseHeaders];
    });
}

function rp_event_date(string $value): DateTimeImmutable
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
        throw new UnexpectedValueException('Event timestamp must include an ISO 8601 timezone');
    }
    $date = new DateTimeImmutable($value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
        throw new UnexpectedValueException('Invalid event timestamp');
    }
    return $date->setTimezone(new DateTimeZone('UTC'));
}

function rp_validate_payload(string $source, array $data, string $rsn): void
{
    if (!is_string($data['name'] ?? null) || rp_normalise_name($data['name']) !== rp_normalise_name($rsn)) {
        throw new UnexpectedValueException('Platform API player identity mismatch');
    }
    if ($source === 'events') {
        if (!isset($data['events']) || !is_array($data['events']) || !array_is_list($data['events'])) {
            throw new UnexpectedValueException('Missing events array');
        }
        foreach ($data['events'] as $event) {
            foreach (['text', 'details', 'date', 'type'] as $field) {
                if (!is_string($event[$field] ?? null)) throw new UnexpectedValueException('Invalid event ' . $field);
            }
            if ($event['text'] === '' || $event['type'] === '') throw new UnexpectedValueException('Empty event text/type');
            rp_event_date($event['date']);
        }
    } elseif ($source === 'skills') {
        rp_player_skills_snapshot($data);
    } elseif ($source === 'hiscores') {
        rp_hiscores($data); // Validate before caching; partial historical records remain valid.
    } elseif ($source === 'quests') {
        rp_quests($data);
    }
}

/** Injectable transport is used only by offline tests; production always uses HTTPS. */
function rp_request(string $source, string $rsn, ?callable $transport = null, ?PDO $pdo = null): array
{
    $result = ['ok' => false, 'http_code' => 0, 'error' => null, 'message' => null,
        'data' => null, 'retry_after' => 0];
    try {
        // Injected transports are offline fixtures; real HTTP always uses the shared limiter.
        $response = $transport !== null ? $transport(rp_url($source, $rsn)) : rp_http_get(rp_url($source, $rsn), $pdo);
        $result['http_code'] = (int)($response['status'] ?? 0);
        $result['retry_after'] = max(0, (int)($response['retry_after'] ?? 0));
        $result['locally_throttled'] = (bool)($response['locally_throttled'] ?? false);
        $data = json_decode((string)($response['body'] ?? ''), true);
        if ($result['http_code'] !== 200 || !empty($response['error']) || isset($data['error'])) {
            $result['error'] = is_string($data['error'] ?? null) ? $data['error'] :
                ($result['http_code'] === 404 ? 'PLAYER_NOT_FOUND' : 'HTTP_ERROR');
            $result['message'] = 'Platform API HTTP status ' . $result['http_code'] . ': ' . $result['error'];
            return $result;
        }
        if (!is_array($data)) throw new UnexpectedValueException('Invalid JSON response');
        rp_validate_payload($source, $data, $rsn);
        $result['ok'] = true;
        $result['data'] = $data;
    } catch (Throwable $e) {
        $result['error'] = 'INVALID_RESPONSE';
        $result['message'] = $e->getMessage();
    }
    return $result;
}

function rp_skill_names(): array
{
    return ['Attack','Defence','Strength','Constitution','Ranged','Prayer','Magic',
        'Cooking','Woodcutting','Fletching','Fishing','Firemaking','Crafting','Smithing','Mining',
        'Herblore','Agility','Thieving','Slayer','Farming','Runecrafting','Hunter','Construction',
        'Summoning','Dungeoneering','Divination','Invention','Archaeology','Necromancy'];
}

/** Use the same ordered JSON and hash for equivalent snapshots from either source. */
function rp_pack_snapshot(array $skills): array
{
    ksort($skills);
    $totalXp = $skills['total']['xp'];
    $json = json_encode($skills, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return [
        'total_xp' => $totalXp,
        'skills_json' => $json,
        'snapshot_hash' => hash('sha256', $totalXp . '|' . $json),
    ];
}

function rp_player_skills_snapshot(array $data): array
{
    $rows = $data['skills'] ?? null;
    $names = rp_skill_names();
    if (!is_array($rows) || count($rows) !== count($names)) {
        throw new UnexpectedValueException('INCOMPLETE_PLAYER_DATA: expected all tracked skills');
    }

    $skills = [];
    $totalXp = 0;
    $totalLevel = 0;
    $aliases = ['Constitution' => 'hitpoints', 'Runecrafting' => 'runecraft'];
    foreach ($names as $name) {
        $key = $aliases[$name] ?? strtolower($name);
        $row = $rows[$key] ?? null;
        if (!is_int($row['fine_xp'] ?? null) || $row['fine_xp'] < 0 ||
            !is_int($row['level'] ?? null) || $row['level'] < 0) {
            throw new UnexpectedValueException('INCOMPLETE_PLAYER_DATA: invalid or missing skill ' . $key);
        }
        // Historical totals sum each skill's whole XP, discarding its fractional tenth.
        $xp = intdiv($row['fine_xp'], 10);
        $skills[$name] = ['level' => $row['level'], 'xp' => $xp];
        $totalXp += $xp;
        $totalLevel += $row['level'];
    }
    $skills['total'] = ['level' => $totalLevel, 'xp' => $totalXp];
    return rp_pack_snapshot($skills);
}

function rp_hiscores(array $data): array
{
    if (!is_array($data['skills'] ?? null) || !$data['skills'] || !array_is_list($data['skills']) ||
        !is_array($data['activities'] ?? null) || !array_is_list($data['activities'])) {
        throw new UnexpectedValueException('Missing Hiscores skills/activities');
    }
    $skills = []; $overall = null; $rows = [];
    foreach ($data['skills'] as $row) {
        foreach (['id','rank','level','xp'] as $key) {
            if (!is_int($row[$key] ?? null) || $row[$key] < -1) throw new UnexpectedValueException('Invalid Hiscores skill ' . $key);
        }
        if (!is_string($row['name'] ?? null)) throw new UnexpectedValueException('Missing skill name');
        $name = ['Hitpoints' => 'Constitution', 'Runecraft' => 'Runecrafting', 'Overall' => 'total'][$row['name']] ?? $row['name'];
        if (isset($skills[$name])) throw new UnexpectedValueException('Duplicate Hiscores skill');
        $skills[$name] = ['level' => $row['level'] < 0 ? null : $row['level'], 'xp' => $row['xp'] < 0 ? null : $row['xp']];
        if ($name === 'total') $overall = $skills[$name];
        $rows[] = ['index' => $row['id'], 'kind' => 'skill', 'name' => $name,
            'rank' => $row['rank'], 'level' => $skills[$name]['level'], 'xp' => $skills[$name]['xp']];
    }
    if ($overall === null) throw new UnexpectedValueException('Missing Overall skill');
    $complete = $overall['xp'] !== null && $overall['level'] !== null;
    foreach (rp_skill_names() as $name) {
        if (!isset($skills[$name]['xp'], $skills[$name]['level'])) $complete = false;
    }
    $snapshot = null;
    if ($complete) {
        // Keep only application skills; future skill support requires an explicit UI/schema decision.
        $stored = array_intersect_key($skills, array_flip(array_merge(['total'], rp_skill_names())));
        $snapshot = rp_pack_snapshot($stored);
    }
    $names = [
        'Bounty Hunters' => 'bounty_hunter', 'Bounty Hunter Rogues' => 'bounty_hunter_rogues',
        'Dominion Tower' => 'dominion_tower', 'The Crucible' => 'the_crucible', 'Castle Wars Games' => 'castle_wars_games',
        'B.A Attackers' => 'barbarian_assault_attackers', 'B.A Defenders' => 'barbarian_assault_defenders',
        'B.A Collectors' => 'barbarian_assault_collectors', 'B.A Healers' => 'barbarian_assault_healers',
        'Duel Tournament' => 'duel_tournament', 'Mobilising Armies' => 'mobilising_armies', 'Conquest' => 'conquest',
        'Fist of Guthix' => 'fist_of_guthix', 'GG: Athletics' => 'gielinor_games_athletics',
        'GG: Resource Race' => 'gielinor_games_resource_race',
        'WE2 Armadyl lifetime contribution' => 'world_event_2_armadyl_lifetime',
        'WE2 Bandos lifetime contribution' => 'world_event_2_bandos_lifetime',
        'WE2 Armadyl PvP kills' => 'world_event_2_armadyl_pvp', 'WE2 Bandos PvP kills' => 'world_event_2_bandos_pvp',
        'Heist: Guard Prestige' => 'heist_guard_level', 'Heist: Robber Prestige' => 'heist_robber_level',
        'CFP: 5 game average' => 'cabbage_facepunch_bonanza', 'Cow tips' => 'april_fools_2015_cow_tipping',
        'Rats Slaughtered' => 'april_fools_2015_rats', 'RuneScore' => 'runescore',
    ];
    foreach (['easy','medium','hard','elite','master'] as $tier) $names['Clue Scrolls (' . $tier . ')'] = 'clue_' . $tier;
    $misc = [];
    foreach ($data['activities'] as $row) {
        if (!is_string($row['name'] ?? null) || !is_int($row['id'] ?? null) ||
            !is_int($row['rank'] ?? null) || !is_int($row['score'] ?? null) || $row['score'] < -1) {
            throw new UnexpectedValueException('Invalid Hiscores activity');
        }
        $key = $names[$row['name']] ?? 'activity_' . substr(hash('sha256', $row['name']), 0, 16);
        if (isset($misc[$key])) throw new UnexpectedValueException('Duplicate Hiscores activity');
        $category = $key === 'runescore' ? 'runescore' : (str_starts_with($key, 'clue_') ? 'clues' : 'misc');
        $entry = ['index' => $row['id'], 'kind' => 'misc', 'key' => $key, 'label' => $row['name'],
            'category' => $category, 'rank' => $row['rank'], 'score' => $row['score'] < 0 ? null : $row['score'],
            'ranked' => $row['rank'] > 0];
        $misc[$key] = $entry; $rows[] = $entry;
    }
    $clues = []; $total = 0;
    foreach (['easy','medium','hard','elite','master'] as $tier) {
        $clues[$tier] = $misc['clue_' . $tier] ?? null;
        $score = $clues[$tier]['score'] ?? null;
        $total = ($score === null || $total === null) ? null : $total + $score;
    }
    $clues['total'] = ['key' => 'clue_total', 'label' => 'Total Clues', 'score' => $total,
        'rank' => null, 'ranked' => false, 'derived' => true];
    return ['snapshot' => $snapshot, 'misc' => $misc, 'rows' => $rows, 'row_count' => count($rows),
        'summary' => ['dominion_tower' => $misc['dominion_tower'] ?? null,
            'runescore' => $misc['runescore'] ?? null, 'clues' => $clues, 'clues_total_from_tiers' => $total]];
}

function rp_quests(array $data): array
{
    if (!is_array($data['quests'] ?? null) || !is_int($data['qp'] ?? null) || !is_int($data['qp_max'] ?? null) ||
        $data['qp'] < 0 || $data['qp_max'] < $data['qp']) throw new UnexpectedValueException('Invalid quest response');
    $totals = ['total' => 0, 'completed' => 0, 'started' => 0, 'not_started' => 0,
        'quest_points_completed' => $data['qp'], 'quest_points_max' => $data['qp_max']];
    $quests = [];
    foreach ($data['quests'] as $id => $q) {
        if (!ctype_digit((string)$id) || !is_string($q['title'] ?? null) || !is_int($q['qp'] ?? null) ||
            !is_int($q['difficulty'] ?? null) || !is_bool($q['members'] ?? null) || !is_bool($q['eligible'] ?? null)) {
            throw new UnexpectedValueException('Invalid quest fields');
        }
        $status = ['COMPLETED' => 'completed', 'STARTED' => 'started', 'NOT_STARTED' => 'not_started'][$q['status'] ?? ''] ?? null;
        if ($status === null) throw new UnexpectedValueException('Unknown quest status');
        $totals['total']++; $totals[$status]++;
        $quests[] = ['id' => (int)$id, 'title' => $q['title'], 'status' => $q['status'],
            'difficulty' => $q['difficulty'], 'members' => $q['members'],
            'questPoints' => $q['qp'], 'userEligible' => $q['eligible']];
    }
    usort($quests, static fn(array $a, array $b): int => strcasecmp($a['title'], $b['title']));
    return ['totals' => $totals, 'quests' => $quests, 'qp' => $data['qp'], 'qp_max' => $data['qp_max']];
}

function rp_poll_schedule(array $result, int $now, int $consecutive429 = 0): array
{
    $partial = !empty($result['sources']['events']['processed_ok']) || !empty($result['sources']['skills']['processed_ok']);
    // A cap never changes this interval. Source-specific retries protect failed providers.
    if (!empty($result['ok']) || $partial) {
        return ['next_poll' => $now + 300, 'http_status' => !empty($result['ok']) ? 200 : 207,
            'consecutive_429' => 0, 'error' => $result['error'] ?? null];
    }
    $rateLimited = false; $retryAt = 0;
    foreach ($result['sources'] ?? [] as $source) {
        if (($source['http_code'] ?? 0) === 429) {
            $rateLimited = true;
            $retryAt = max($retryAt, strtotime(($source['retry_at_utc'] ?? '') . ' UTC') ?: 0);
        }
    }
    $count = $rateLimited ? $consecutive429 + 1 : 0;
    $delay = $rateLimited ? min(7200, 300 * (2 ** min($count - 1, 6))) : 1800;
    return ['next_poll' => max($now + $delay, $retryAt), 'http_status' => $rateLimited ? 429 : null,
        'consecutive_429' => $count, 'error' => $result['error'] ?? 'Platform sync failed'];
}
