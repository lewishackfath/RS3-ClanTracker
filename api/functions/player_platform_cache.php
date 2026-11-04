<?php
declare(strict_types=1);
require_once __DIR__ . '/platform_storage.php';

function tracker_cache_ttl_seconds(string $envKey, int $defaultSeconds): int {
    $raw = getenv($envKey);
    if ($raw === false || trim((string)$raw) === '') return $defaultSeconds;
    $n = (int)$raw;
    return $n > 0 ? $n : $defaultSeconds;
}

function tracker_utc_now_string(): string {
    return gmdate('Y-m-d H:i:s');
}

function tracker_is_cache_fresh(?string $updatedAtUtc, int $ttlSeconds): bool {
    if (!$updatedAtUtc) return false;
    $ts = strtotime($updatedAtUtc . ' UTC');
    if ($ts === false || $ts <= 0) return false;
    return (time() - $ts) < $ttlSeconds;
}

function tracker_cache_table_name(string $cacheName): string {
    $allowed = [
        'hiscores_lite' => 'member_hiscores_lite',
        'rm_quests' => 'member_rm_quests',
    ];
    if (!isset($allowed[$cacheName])) {
        throw new InvalidArgumentException('Unknown cache table');
    }
    return $allowed[$cacheName];
}

function tracker_read_json_cache(PDO $pdo, string $cacheName, int $memberId, int $clanId): ?array {
    $table = tracker_cache_table_name($cacheName);
    $stmt = $pdo->prepare("\n        SELECT json_data, updated_at\n        FROM {$table}\n        WHERE member_id = :member_id\n          AND member_clan_id = :member_clan_id\n        LIMIT 1\n    ");
    $stmt->execute([
        ':member_id' => $memberId,
        ':member_clan_id' => $clanId,
    ]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $decoded = json_decode((string)($row['json_data'] ?? ''), true);
    if (!is_array($decoded)) return null;

    return [
        'data' => $decoded,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function tracker_write_json_cache(PDO $pdo, string $cacheName, int $memberId, int $clanId, array $payload, ?string $fetchedAt = null): void {
    $table = tracker_cache_table_name($cacheName);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode JSON cache payload');
    }

    $stmt = $pdo->prepare("\n        INSERT INTO {$table}\n            (member_id, member_clan_id, json_data, updated_at)\n        VALUES\n            (:member_id, :member_clan_id, :json_data, :updated_at)\n        ON DUPLICATE KEY UPDATE\n            json_data = VALUES(json_data),\n            updated_at = VALUES(updated_at)\n    ");
    $stmt->execute([
        ':member_id' => $memberId,
        ':member_clan_id' => $clanId,
        ':json_data' => $json,
        ':updated_at' => $fetchedAt ?? tracker_utc_now_string(),
    ]);
}

/** Retain public player/cache response keys while sharing raw requests with the poller. */
function tracker_get_platform_cache(PDO $pdo, int $memberId, int $clanId, string $rsn, string $source, bool $allowRefresh, ?callable $transport = null): array
{
    $cacheName = $source === 'quests' ? 'rm_quests' : 'hiscores_lite';
    $sourceName = $source === 'quests' ? 'platform_quests' : 'platform_hiscores';
    $ttl = $source === 'quests'
        ? tracker_cache_ttl_seconds('TRACKER_RM_QUESTS_TTL_SECONDS', 6 * 60 * 60)
        : tracker_cache_ttl_seconds('TRACKER_HISCORES_LITE_TTL_SECONDS', 300);
    $cached = tracker_read_json_cache($pdo, $cacheName, $memberId, $clanId);
    $payload = $cached['data'] ?? null;
    if ($payload && rp_normalise_name((string)($payload['rsn'] ?? '')) !== rp_normalise_name($rsn)) $payload = null;
    $fresh = $payload && !empty($payload['ok']) && ($payload['schema_version'] ?? 0) === 2 &&
        ($payload['source'] ?? '') === $sourceName && tracker_is_cache_fresh($cached['updated_at'], $ttl);
    if ($fresh || !$allowRefresh) {
        $result = $payload ?? ['ok' => false, 'http_code' => 0, 'rsn' => $rsn, 'source' => $sourceName,
            'error' => 'Data has not been fetched yet', 'hint' => null, 'totals' => null,
            'quests' => [], 'misc' => [], 'summary' => null, 'rows' => []];
        $result['cache'] = ['hit' => $payload !== null, 'stale' => !$fresh, 'needs_refresh' => !$fresh,
            'deferred_refresh' => !$allowRefresh && !$fresh, 'updated_at_utc' => $cached['updated_at'] ?? null, 'ttl_seconds' => $ttl];
        return $result;
    }
    try {
        $fetch = rp_cached_fetch($pdo, $memberId, $source, $rsn, $ttl, $transport);
        if (!$fetch['ok']) {
            $result = $payload ?? ['ok' => false, 'rsn' => $rsn, 'source' => $sourceName,
                'totals' => null, 'quests' => [], 'misc' => [], 'summary' => null, 'rows' => []];
            $result['http_code'] = $fetch['http_code'];
            $result['error'] = $fetch['error'];
            $result['hint'] = $fetch['message'];
            $result['cache'] = ['hit' => $payload !== null, 'stale' => true, 'needs_refresh' => false,
                'deferred_refresh' => false, 'updated_at_utc' => $cached['updated_at'] ?? null,
                'ttl_seconds' => $ttl, 'retry_at_utc' => $fetch['retry_at_utc'], 'refresh_error' => $fetch['message']];
            return $result; // Never overwrite last good quest/Hiscores data with errors or zero totals.
        }
        $normal = $source === 'quests' ? rp_quests($fetch['data']) : rp_hiscores($fetch['data']);
        unset($normal['snapshot']);
        $result = array_merge($normal, ['ok' => true, 'http_code' => 200, 'rsn' => $rsn,
            'source' => $sourceName, 'schema_version' => 2, 'fetched_at_utc' => $fetch['fetched_at_utc'],
            'error' => null, 'hint' => null, 'loggedIn' => null]);
        if ($source === 'hiscores') $result['skills_ignored_for_ui'] = true;
        tracker_write_json_cache($pdo, $cacheName, $memberId, $clanId, $result, $fetch['fetched_at_utc']);
        $result['cache'] = ['hit' => $fetch['cache_hit'], 'stale' => false, 'needs_refresh' => false,
            'deferred_refresh' => false, 'updated_at_utc' => $fetch['fetched_at_utc'], 'ttl_seconds' => $ttl];
        return $result;
    } catch (Throwable $e) {
        $result = $payload ?? ['ok' => false, 'rsn' => $rsn, 'source' => $sourceName,
            'totals' => null, 'quests' => [], 'misc' => [], 'summary' => null, 'rows' => []];
        $result['error'] = 'Platform refresh failed';
        $result['hint'] = $e->getMessage();
        $result['cache'] = ['hit' => $payload !== null, 'stale' => true, 'needs_refresh' => false,
            'deferred_refresh' => false, 'updated_at_utc' => $cached['updated_at'] ?? null,
            'ttl_seconds' => $ttl, 'refresh_error' => $e->getMessage()];
        return $result;
    }
}
