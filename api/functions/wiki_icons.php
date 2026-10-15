<?php
declare(strict_types=1);

/** Wiki downloads shared by the image endpoint and activity checker. */
function wi_image_valid(string $bytes): bool
{
    return $bytes !== '' && @getimagesizefromstring($bytes) !== false;
}

/** Redirects stay on the configured host; user item names cannot select a host. */
function wi_download(string $url, float $deadline, string $host = 'runescape.wiki', bool $allowHttp = false): array
{
    for ($redirect = 0; $redirect < 4; $redirect++) {
        $parts = parse_url($url);
        if (!in_array($parts['scheme'] ?? '', $allowHttp ? ['https', 'http'] : ['https'], true) || ($parts['host'] ?? '') !== $host
            || isset($parts['user']) || isset($parts['port'])) {
            return ['status' => 'temporary', 'reason' => 'wiki_redirect'];
        }
        $remaining = (int)(($deadline - microtime(true)) * 1000);
        if ($remaining < 100) return ['status' => 'temporary', 'reason' => 'wiki_timeout'];
        $body = '';
        $location = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(1500, $remaining),
            CURLOPT_TIMEOUT_MS => $remaining,
            CURLOPT_USERAGENT => 'RS3-ClanTracker/1.0 icon checker',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 5 * 1024 * 1024) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9));
                return strlen($header);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($ok === false) return ['status' => 'temporary', 'reason' => 'wiki_fetch_failed'];
        if ($code >= 300 && $code < 400 && $location !== '') {
            $url = str_starts_with($location, '/') && !str_starts_with($location, '//')
                ? $parts['scheme'] . '://' . $host . $location : $location;
            continue;
        }
        if ($code === 404 || $code === 410) return ['status' => 'missing', 'reason' => 'wiki_not_found'];
        if ($code >= 200 && $code < 300 && wi_image_valid($body)) {
            return ['status' => 'resolved', 'bytes' => $body];
        }
        return ['status' => 'temporary', 'reason' => 'wiki_http_' . $code];
    }
    return ['status' => 'temporary', 'reason' => 'wiki_redirect'];
}

/**
 * Negative results are cached too: viewing the same missing drop never causes
 * an unbounded stream of Wiki requests. Force is reserved for queued rechecks.
 * The optional transport makes network outcomes deterministic in tests.
 */
function wi_resolve(string $root, string $item, bool $force = false, ?callable $transport = null): array
{
    $item = mb_substr(trim(preg_replace('/\s+/u', ' ', str_replace(['’', '‘', '`'], "'", $item)) ?? $item), 0, 120);
    if ($item === '') return ['status' => 'missing', 'reason' => 'empty_item'];
    $safe = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($item)) ?? '', '_') ?: 'item';
    $dir = $root . '/cache/item-icons';
    $key = $safe . '_' . substr(sha1($item), 0, 10);
    $name = rawurlencode(str_replace(' ', '_', $item) . '.png');
    return wi_cached_icon($dir, $key, ['https://runescape.wiki/images/' . $name,
        'https://runescape.wiki/w/Special:FilePath/' . $name], $force, $transport ?? 'wi_download');
}

/** URL must come from an administrator-maintained map, never a request field. */
function wi_mapped_icon(string $root, string $url, bool $force = false, ?callable $transport = null): array
{
    $parts = parse_url($url);
    if (!in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
        return ['status' => 'missing', 'reason' => 'invalid_mapped_url'];
    }
    $download = $transport ?? static fn(string $url, float $deadline): array => wi_download($url, $deadline, $parts['host'], $parts['scheme'] === 'http');
    return wi_cached_icon($root . '/cache/mapped-icons', hash('sha256', $url), [$url], $force, $download);
}

function wi_cached_icon(string $dir, string $key, array $urls, bool $force, callable $download): array
{
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $base = $dir . '/' . $key;
    $path = $base . '.png';
    // A known valid image remains usable if the Wiki is temporarily unavailable.
    $cached = is_file($path) && wi_image_valid((string)@file_get_contents($path));
    if ($cached && !$force && time() - (int)filemtime($path) < 14 * 86400) {
        return ['status' => 'resolved', 'file' => $path];
    }
    $negative = json_decode((string)@file_get_contents($base . '.json'), true);
    if (!$force && is_array($negative) && ($negative['retry_at'] ?? 0) > time()) {
        return $cached ? ['status' => 'resolved', 'file' => $path] : $negative;
    }
    $lock = @fopen($base . '.lock', 'c');
    if (!$lock) return $cached ? ['status' => 'resolved', 'file' => $path] : ['status' => 'temporary', 'reason' => 'icon_cache_unwritable'];
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return $cached ? ['status' => 'resolved', 'file' => $path] : ['status' => 'temporary', 'reason' => 'wiki_busy'];
    }
    try {
        $deadline = microtime(true) + 5;
        $result = ['status' => 'missing', 'reason' => 'wiki_not_found'];
        foreach ($urls as $url) {
            $attempt = $download($url, $deadline);
            if ($attempt['status'] === 'resolved' && wi_image_valid($attempt['bytes'] ?? '')) {
                $tmp = tempnam($dir, '.icon-');
                if ($tmp !== false && file_put_contents($tmp, $attempt['bytes']) !== false && rename($tmp, $path)) {
                    @unlink($base . '.json');
                    return ['status' => 'resolved', 'file' => $path];
                }
                if ($tmp !== false) @unlink($tmp);
                return ['status' => 'temporary', 'reason' => 'icon_cache_unwritable'];
            }
            if ($attempt['status'] === 'temporary') $result = $attempt;
        }
        $result['retry_at'] = time() + ($result['status'] === 'temporary' ? 300 : 21600);
        @file_put_contents($base . '.json', json_encode($result), LOCK_EX);
        return $cached ? ['status' => 'resolved', 'file' => $path] : $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
