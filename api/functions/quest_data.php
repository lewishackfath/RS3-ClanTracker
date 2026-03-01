<?php
declare(strict_types=1);

/**
 * Live RuneMetrics quest completion fetcher.
 *
 * Endpoint:
 *   https://apps.runescape.com/runemetrics/quests?user=<RSN>
 *
 * Returns:
 *   [
 *     'ok' => bool,
 *     'http_code' => int,
 *     'rsn' => string,
 *     'data' => array|null,   // decoded JSON
 *     'error' => string|null,
 *     'hint' => string|null,
 *   ]
 */
function get_quest_data(string $rsn): array {
    $rsn = trim($rsn);
    if ($rsn === '') {
        return ['ok' => false, 'http_code' => 0, 'rsn' => '', 'data' => null, 'error' => 'player required', 'hint' => null];
    }

    // RuneScape endpoints typically use underscores for spaces.
    $apiName = preg_replace('/\s+/', '_', $rsn) ?? $rsn;
    $url = 'https://apps.runescape.com/runemetrics/quests?user=' . rawurlencode($apiName);

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'http_code' => 0, 'rsn' => $rsn, 'data' => null, 'error' => 'curl_init failed', 'hint' => null];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json, text/plain, */*',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ],
        // Some Jagex endpoints are picky about UA; set one explicitly.
        CURLOPT_USERAGENT => '24K-Tracker/1.0 (+https://tracker.24krs.com.au)',
    ]);

    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $raw === '') {
        return ['ok' => false, 'http_code' => $http, 'rsn' => $rsn, 'data' => null, 'error' => 'request failed', 'hint' => ($err ?: null)];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'http_code' => $http, 'rsn' => $rsn, 'data' => null, 'error' => 'invalid json', 'hint' => substr($raw, 0, 240)];
    }

    if ($http !== 200) {
        return ['ok' => false, 'http_code' => $http, 'rsn' => $rsn, 'data' => $data, 'error' => 'runemetrics http ' . $http, 'hint' => (isset($data['error']) ? (string)$data['error'] : null)];
    }

    return ['ok' => true, 'http_code' => $http, 'rsn' => $rsn, 'data' => $data, 'error' => null, 'hint' => null];
}