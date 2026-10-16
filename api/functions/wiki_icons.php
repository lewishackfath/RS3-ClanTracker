<?php
declare(strict_types=1);

/** Wiki downloads shared by the image endpoint and activity checker. */
function wi_image_valid(string $bytes): bool
{
    return $bytes !== '' && @getimagesizefromstring($bytes) !== false;
}

/** Redirects stay on the configured host; user item names cannot select a host. */
function wi_fetch(string $url, float $deadline, string $host = 'runescape.wiki', bool $allowHttp = false): array
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
        if ($code >= 200 && $code < 300) {
            return ['status' => 'resolved', 'bytes' => $body];
        }
        return ['status' => 'temporary', 'reason' => 'wiki_http_' . $code];
    }
    return ['status' => 'temporary', 'reason' => 'wiki_redirect'];
}

function wi_download(string $url, float $deadline, string $host = 'runescape.wiki', bool $allowHttp = false): array
{
    $result = wi_fetch($url, $deadline, $host, $allowHttp);
    if ($result['status'] === 'resolved' && !wi_image_valid($result['bytes'])) {
        return ['status' => 'temporary', 'reason' => 'wiki_invalid_image'];
    }
    return $result;
}

/** Ignore case and separators, but retain every word, number and item qualifier. */
function wi_words(string $name): string
{
    $name = str_replace(['’', '‘', '`'], "'", $name);
    return trim(preg_replace("/[^\\p{L}\\p{N}']+/u", ' ', mb_strtolower($name, 'UTF-8')) ?? '');
}

/** Search canonical File titles instead of assuming the Wiki's capitalization. */
function wi_item_image(string $item, float $deadline, callable $request): array
{
    $words = wi_words($item);
    $url = 'https://runescape.wiki/api.php?' . http_build_query([
        'action' => 'query', 'generator' => 'search', 'gsrnamespace' => 6,
        'gsrsearch' => 'intitle:"' . $words . '"', 'gsrlimit' => 50,
        'prop' => 'imageinfo', 'iiprop' => 'url', 'format' => 'json', 'formatversion' => 2,
    ], '', '&', PHP_QUERY_RFC3986);
    // Leave time for the image itself, or direct filename fallback if search fails.
    $search = $request($url, min($deadline, microtime(true) + 2));
    $result = ['status' => 'missing', 'reason' => 'wiki_not_found'];
    $detail = [];
    $standard = [];
    if ($search['status'] === 'resolved') {
        $data = json_decode($search['bytes'] ?? '', true);
        if (!is_array($data) || isset($data['error'])
            || (!array_key_exists('batchcomplete', $data) && !isset($data['query']['pages']))
            || (isset($data['query']['pages']) && !is_array($data['query']['pages']))) {
            $result = ['status' => 'temporary', 'reason' => 'wiki_search_failed'];
        } else {
            foreach ($data['query']['pages'] ?? [] as $page) {
                $title = $page['title'] ?? '';
                if (!preg_match('/^File:(.+)\.(?:png|webp|jpe?g|gif)$/iu', $title, $match)) continue;
                $name = wi_words($match[1]);
                $image = $page['imageinfo'][0]['url'] ?? null;
                if (!is_string($image)) continue;
                if ($name === $words . ' detail') $detail[] = $image;
                elseif ($name === $words) $standard[] = $image;
            }
            // An incomplete search cannot prove that an image is missing.
            if (isset($data['continue'])) $result = ['status' => 'temporary', 'reason' => 'wiki_search_incomplete'];
        }
    } else {
        // A failed API request does not establish that the item is absent.
        $result = ['status' => 'temporary', 'reason' => $search['reason'] ?? 'wiki_search_failed'];
    }
    foreach ([$item . ' detail', $item] as $index => $name) {
        $filename = rawurlencode(str_replace(' ', '_', $name) . '.png');
        $urls = $index === 0 ? $detail : $standard;
        $urls[] = 'https://runescape.wiki/images/' . $filename;
        $urls[] = 'https://runescape.wiki/w/Special:FilePath/' . $filename;
        $attempt = wi_first_image(array_unique($urls), $deadline, $request);
        if ($attempt['status'] === 'resolved') return $attempt;
        if ($attempt['status'] === 'temporary') $result = $attempt;
    }
    return $result;
}

function wi_first_image(array $urls, float $deadline, callable $request): array
{
    $result = ['status' => 'missing', 'reason' => 'wiki_not_found'];
    foreach ($urls as $url) {
        $attempt = $request($url, $deadline);
        if ($attempt['status'] === 'resolved') {
            if (wi_image_valid($attempt['bytes'] ?? '')) return $attempt;
            $attempt = ['status' => 'temporary', 'reason' => 'wiki_invalid_image'];
        }
        if ($attempt['status'] === 'temporary') $result = $attempt;
    }
    return $result;
}

/**
 * Negative results are cached too: viewing the same missing drop never causes
 * an unbounded stream of Wiki requests. Force is reserved for queued rechecks.
 * The optional transport returns raw API/image responses for deterministic tests.
 */
function wi_resolve(string $root, string $item, bool $force = false, ?callable $transport = null): array
{
    $item = mb_substr(trim(preg_replace('/\s+/u', ' ', str_replace(['’', '‘', '`'], "'", $item)) ?? $item), 0, 120);
    // Explicit aliases ending in "Detail" use the same preference and fallback.
    $item = trim(preg_replace('/[\s_]+detail$/iu', '', $item) ?? $item);
    $words = wi_words($item);
    if ($words === '') return ['status' => 'missing', 'reason' => 'empty_item'];
    $safe = trim(preg_replace('/[^a-z0-9]+/', '_', $words) ?? '', '_') ?: 'item';
    $dir = $root . '/cache/item-icons';
    // Version the key so old standard-image/negative caches cannot hide details.
    $key = 'v2_' . $safe . '_' . substr(sha1($words), 0, 10);
    return wi_cached_icon($dir, $key, $force,
        static fn(float $deadline): array => wi_item_image($item, $deadline, $transport ?? 'wi_fetch'));
}

/** URL must come from an administrator-maintained map, never a request field. */
function wi_mapped_icon(string $root, string $url, bool $force = false, ?callable $transport = null): array
{
    $parts = parse_url($url);
    if (!in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
        return ['status' => 'missing', 'reason' => 'invalid_mapped_url'];
    }
    $download = $transport ?? static fn(string $url, float $deadline): array => wi_download($url, $deadline, $parts['host'], $parts['scheme'] === 'http');
    return wi_cached_icon($root . '/cache/mapped-icons', hash('sha256', $url), $force,
        static fn(float $deadline): array => wi_first_image([$url], $deadline, $download));
}

function wi_cached_icon(string $dir, string $key, bool $force, callable $fetch): array
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
        $result = $fetch(microtime(true) + 5);
        if ($result['status'] === 'resolved') {
            $tmp = tempnam($dir, '.icon-');
            if ($tmp !== false && file_put_contents($tmp, $result['bytes']) !== false && rename($tmp, $path)) {
                @unlink($base . '.json');
                return ['status' => 'resolved', 'file' => $path];
            }
            if ($tmp !== false) @unlink($tmp);
            return ['status' => 'temporary', 'reason' => 'icon_cache_unwritable'];
        }
        $result['retry_at'] = time() + ($result['status'] === 'temporary' ? 300 : 21600);
        @file_put_contents($base . '.json', json_encode($result), LOCK_EX);
        return $cached ? ['status' => 'resolved', 'file' => $path] : $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
