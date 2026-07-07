<?php
declare(strict_types=1);

require_once __DIR__ . '/_db.php';

function tracker_boss_csv_get_param(string $key, int $maxLen = 128): string {
    $v = (string)($_REQUEST[$key] ?? '');
    $v = trim($v);
    if ($v === '') return '';
    if (mb_strlen($v) > $maxLen) $v = mb_substr($v, 0, $maxLen);
    return $v;
}

function tracker_boss_csv_column_type(PDO $pdo, string $table, string $column): ?string {
    $stmt = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column LIMIT 1");
    $stmt->execute([':table' => $table, ':column' => $column]);
    $type = $stmt->fetchColumn();
    return $type ? (string)$type : null;
}

function tracker_boss_csv_ensure_submissions_table(PDO $pdo): void {
    $memberIdType = tracker_boss_csv_column_type($pdo, 'members', 'id') ?: 'INT';
    $clanIdType = tracker_boss_csv_column_type($pdo, 'clans', 'id') ?: 'INT';

    $pdo->exec("CREATE TABLE IF NOT EXISTS `member_boss_collection_submissions` (
        `id` BIGINT NOT NULL AUTO_INCREMENT,
        `submission_uuid` CHAR(32) NOT NULL,
        `member_id` {$memberIdType} NOT NULL,
        `member_clan_id` {$clanIdType} NOT NULL,
        `member_rsn` VARCHAR(32) NOT NULL,
        `boss_key` VARCHAR(96) NOT NULL,
        `boss_name` VARCHAR(120) NOT NULL,
        `item_key` VARCHAR(140) NOT NULL,
        `item_name` VARCHAR(180) NOT NULL,
        `requested_collected` TINYINT(1) NOT NULL,
        `current_collected` TINYINT(1) NOT NULL DEFAULT 0,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `submitter_note` VARCHAR(255) NULL,
        `submitted_at_utc` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        `reviewed_by` VARCHAR(120) NULL,
        `reviewed_at_utc` DATETIME(3) NULL,
        `review_note` VARCHAR(255) NULL,
        `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        PRIMARY KEY (`id`),
        KEY `idx_boss_submission_uuid` (`submission_uuid`),
        KEY `idx_boss_submission_status` (`status`,`submitted_at_utc`),
        KEY `idx_boss_submission_member` (`member_clan_id`,`member_id`),
        KEY `idx_boss_submission_item` (`boss_key`,`item_key`),
        CONSTRAINT `fk_boss_submission_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_boss_submission_clan` FOREIGN KEY (`member_clan_id`) REFERENCES `clans`(`id`)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tracker_boss_csv_clean_item_name(?string $value): ?string {
    $s = trim((string)($value ?? ''));
    if ($s === '') return null;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    $s = trim($s, "\"'“”‘’. ");

    $path = __DIR__ . '/../assets/activity/item_name_cleanup.json';
    $rules = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    if (is_array($rules)) {
        foreach (($rules['replacements'] ?? []) as $rule) {
            if (!is_array($rule) || empty($rule['from'])) continue;
            $flags = preg_replace('/[^imsxuADSUXJ]/', '', (string)($rule['flags'] ?? 'i')) ?: 'i';
            $pattern = '~' . str_replace('~', '\\~', (string)$rule['from']) . '~' . $flags;
            $replaced = @preg_replace($pattern, (string)($rule['to'] ?? ''), $s);
            if (is_string($replaced)) $s = $replaced;
        }
        foreach (($rules['strip_prefixes'] ?? []) as $prefix) {
            $prefix = trim((string)$prefix);
            if ($prefix !== '') $s = preg_replace('/^' . preg_quote($prefix, '/') . '\s+/i', '', $s) ?? $s;
        }
        foreach (($rules['strip_suffixes'] ?? []) as $suffix) {
            $suffix = trim((string)$suffix);
            if ($suffix !== '') $s = preg_replace('/\s+' . preg_quote($suffix, '/') . '$/i', '', $s) ?? $s;
        }
    }

    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    $s = trim($s, "\"'“”‘’. ");
    return $s !== '' ? $s : null;
}

function tracker_boss_csv_item_key(?string $value): string {
    $s = tracker_boss_csv_clean_item_name($value) ?? trim((string)($value ?? ''));
    if ($s === '') return '';
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace(['’', '‘', '`'], "'", $s);
    $s = mb_strtolower($s);
    $s = preg_replace('/&/u', ' and ', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

function tracker_boss_csv_default_aliases(): array {
    return [
        'Bandos shield' => 'Bandos warshield',
        'Hiss of Saradomin' => "Saradomin's hiss",
        'Bill (pet)' => 'Bill (pet) pet',
        'Vitalis' => 'Vitalis (pet)',
        'Zammo the Rak' => 'Zammo the Rak pet',
        'Silverspines' => 'Silver spines',
        'Sanguine Spines' => 'Sanguine spines',
    ];
}

function tracker_boss_csv_alias_names_for_item(string $itemName): array {
    $itemKey = tracker_boss_csv_item_key($itemName);
    if ($itemKey === '') return [];

    $pairs = [];
    $add = static function($from, $to) use (&$pairs): void {
        $from = tracker_boss_csv_clean_item_name(is_scalar($from) ? (string)$from : null) ?? '';
        $to = tracker_boss_csv_clean_item_name(is_scalar($to) ? (string)$to : null) ?? '';
        if ($from !== '' && $to !== '') $pairs[] = [$from, $to];
    };
    foreach (tracker_boss_csv_default_aliases() as $from => $to) $add($from, $to);

    $path = __DIR__ . '/../assets/activity/item_icon_alias.json';
    $json = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    if (is_array($json)) {
        $sourceObject = (isset($json['aliases']) && is_array($json['aliases']) && array_keys($json['aliases']) !== range(0, count($json['aliases']) - 1))
            ? $json['aliases']
            : $json;
        foreach ($sourceObject as $from => $to) {
            if (is_string($to)) $add($from, $to);
        }
        foreach (($json['aliases'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $add(
                $row['from'] ?? $row['source'] ?? $row['runemetrics'] ?? $row['activity_name'] ?? null,
                $row['to'] ?? $row['target'] ?? $row['wiki'] ?? $row['item_name'] ?? null
            );
        }
    }

    $out = [];
    $seen = [$itemKey => true];
    foreach ($pairs as [$from, $to]) {
        $fromKey = tracker_boss_csv_item_key($from);
        $toKey = tracker_boss_csv_item_key($to);
        if ($fromKey === $itemKey && $toKey !== '' && !isset($seen[$toKey])) {
            $seen[$toKey] = true;
            $out[] = $to;
        }
        if ($toKey === $itemKey && $fromKey !== '' && !isset($seen[$fromKey])) {
            $seen[$fromKey] = true;
            $out[] = $from;
        }
    }
    return $out;
}

function tracker_boss_csv_load_definitions(): array {
    $baseDir = __DIR__ . '/../assets/boss-log';
    $indexPath = $baseDir . '/bosses.json';
    $out = ['bosses' => [], 'total_items' => 0];
    $indexJson = is_file($indexPath) ? json_decode((string)@file_get_contents($indexPath), true) : null;
    if (!is_array($indexJson)) return $out;

    foreach (($indexJson['bosses'] ?? []) as $bossRow) {
        if (!is_array($bossRow)) continue;
        $bossKey = trim((string)($bossRow['key'] ?? ''));
        $bossName = trim((string)($bossRow['name'] ?? ''));
        $file = str_replace(['\\', '..'], ['/', ''], trim((string)($bossRow['file'] ?? '')));
        if ($bossKey === '' || $bossName === '' || $file === '') continue;
        $path = $baseDir . '/' . ltrim($file, '/');
        $json = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
        if (!is_array($json)) continue;

        $items = [];
        foreach (($json['items'] ?? []) as $idx => $itemRow) {
            if (is_string($itemRow)) {
                $itemName = trim($itemRow);
                $aliases = [];
                $order = $idx + 1;
            } elseif (is_array($itemRow)) {
                $itemName = trim((string)($itemRow['name'] ?? ''));
                $aliases = [];
                foreach (($itemRow['aliases'] ?? []) as $alias) {
                    $alias = trim((string)$alias);
                    if ($alias !== '') $aliases[] = $alias;
                }
                $order = isset($itemRow['order']) && is_numeric($itemRow['order']) ? (int)$itemRow['order'] : ($idx + 1);
            } else {
                continue;
            }
            if ($itemName === '') continue;
            foreach (tracker_boss_csv_alias_names_for_item($itemName) as $alias) $aliases[] = $alias;
            $itemKey = tracker_boss_csv_item_key($itemName);
            if ($itemKey === '') continue;
            $dedupedAliases = [];
            $seen = [$itemKey => true];
            foreach ($aliases as $alias) {
                $alias = tracker_boss_csv_clean_item_name((string)$alias) ?? trim((string)$alias);
                $aliasKey = tracker_boss_csv_item_key($alias);
                if ($alias === '' || $aliasKey === '' || isset($seen[$aliasKey])) continue;
                $seen[$aliasKey] = true;
                $dedupedAliases[] = $alias;
            }
            $items[] = ['key' => $itemKey, 'name' => $itemName, 'aliases' => $dedupedAliases, 'order' => $order];
        }
        usort($items, static fn(array $a, array $b): int => ((int)$a['order']) <=> ((int)$b['order']));
        $out['total_items'] += count($items);
        $out['bosses'][] = ['key' => $bossKey, 'name' => $bossName, 'items' => $items];
    }
    usort($out['bosses'], static fn(array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));
    return $out;
}

function tracker_boss_csv_find_member(PDO $pdo, string $player): ?array {
    $playerNorm = tracker_normalise($player);
    $playerNormNoSpaces = str_replace(' ', '', $playerNorm);
    $playerRawNoSpaces = str_replace(' ', '', $player);
    $preferredClanId = (int)((string)(getenv('TRACKER_CLAN_ID') ?: '0'));
    $stmt = $pdo->prepare("SELECT m.id, m.clan_id, m.rsn, m.rsn_normalised, m.is_active, c.name AS clan_name
        FROM members m
        JOIN clans c ON c.id = m.clan_id
        WHERE c.is_enabled = 1
          AND c.inactive_at IS NULL
          AND (
            m.rsn_normalised = :rn
            OR REPLACE(m.rsn_normalised, ' ', '') = :rnns
            OR m.rsn = :raw
            OR REPLACE(m.rsn, ' ', '') = :rawns
          )
        ORDER BY CASE WHEN m.clan_id = :preferredClanId THEN 0 ELSE 1 END, m.is_active DESC, m.updated_at DESC
        LIMIT 1");
    $stmt->execute([
        ':rn' => $playerNorm,
        ':rnns' => $playerNormNoSpaces,
        ':raw' => $player,
        ':rawns' => $playerRawNoSpaces,
        ':preferredClanId' => $preferredClanId,
    ]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function tracker_boss_csv_fetch_collection(PDO $pdo, int $memberId, int $clanId): array {
    try {
        $stmt = $pdo->prepare("SELECT boss_key, item_key FROM member_boss_collection_log WHERE member_id = :member_id AND member_clan_id = :clan_id");
        $stmt->execute([':member_id' => $memberId, ':clan_id' => $clanId]);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach (($stmt->fetchAll() ?: []) as $row) {
        $key = (string)($row['boss_key'] ?? '') . '|' . (string)($row['item_key'] ?? '');
        if ($key !== '|') $out[$key] = true;
    }
    return $out;
}

function tracker_boss_csv_definition_indexes(array $definitions): array {
    $bossLookup = [];
    $itemLookup = [];
    foreach (($definitions['bosses'] ?? []) as $boss) {
        if (!is_array($boss)) continue;
        $bossKey = (string)($boss['key'] ?? '');
        $bossName = (string)($boss['name'] ?? '');
        if ($bossKey === '' || $bossName === '') continue;
        $bossLookup[tracker_boss_csv_item_key($bossKey)] = $boss;
        $bossLookup[tracker_boss_csv_item_key($bossName)] = $boss;
        foreach (($boss['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $itemKey = (string)($item['key'] ?? '');
            $itemName = (string)($item['name'] ?? '');
            if ($itemKey === '' || $itemName === '') continue;
            $ref = ['boss' => $boss, 'item' => $item];
            $itemLookup[$bossKey][$itemKey] = $ref;
            $itemLookup[$bossKey][tracker_boss_csv_item_key($itemName)] = $ref;
            foreach (($item['aliases'] ?? []) as $alias) {
                $aliasKey = tracker_boss_csv_item_key((string)$alias);
                if ($aliasKey !== '') $itemLookup[$bossKey][$aliasKey] = $ref;
            }
        }
    }
    return [$bossLookup, $itemLookup];
}

function tracker_boss_csv_bool(?string $value): ?bool {
    $s = mb_strtolower(trim((string)($value ?? '')));
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    if (in_array($s, ['1', 'y', 'yes', 'true', 'collected', 'found', 'complete', 'completed'], true)) return true;
    if (in_array($s, ['0', 'n', 'no', 'false', 'missing', 'not collected', 'uncollected', 'incomplete', ''], true)) return false;
    return null;
}

function tracker_boss_csv_download(PDO $pdo, array $member, array $definitions): void {
    $collection = tracker_boss_csv_fetch_collection($pdo, (int)$member['id'], (int)$member['clan_id']);
    $filename = preg_replace('/[^a-z0-9_\-]+/i', '_', (string)$member['rsn']) ?: 'player';
    $filename .= '_boss_drop_log.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $out = fopen('php://output', 'wb');
    if ($out === false) exit;
    fputcsv($out, ['Boss', 'Boss Item', 'Is collected']);
    foreach (($definitions['bosses'] ?? []) as $boss) {
        if (!is_array($boss)) continue;
        $bossKey = (string)($boss['key'] ?? '');
        foreach (($boss['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $compound = $bossKey . '|' . (string)($item['key'] ?? '');
            fputcsv($out, [(string)($boss['name'] ?? ''), (string)($item['name'] ?? ''), isset($collection[$compound]) ? 'yes' : 'no']);
        }
    }
    fclose($out);
    exit;
}

function tracker_boss_csv_upload(PDO $pdo, array $member, array $definitions): void {
    tracker_boss_csv_ensure_submissions_table($pdo);

    if (empty($_FILES['drop_log_csv']) || !is_array($_FILES['drop_log_csv'])) {
        tracker_json(['ok' => false, 'error' => 'No CSV file was uploaded.'], 400);
    }
    $file = $_FILES['drop_log_csv'];
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        tracker_json(['ok' => false, 'error' => 'CSV upload failed.'], 400);
    }
    if ((int)($file['size'] ?? 0) > 1024 * 1024) {
        @unlink((string)($file['tmp_name'] ?? ''));
        tracker_json(['ok' => false, 'error' => 'CSV file is too large. Maximum size is 1 MB.'], 400);
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $handle = is_file($tmp) ? fopen($tmp, 'rb') : false;
    if ($handle === false) {
        @unlink($tmp);
        tracker_json(['ok' => false, 'error' => 'Could not read uploaded CSV.'], 400);
    }

    $headers = fgetcsv($handle);
    if (!is_array($headers)) {
        fclose($handle);
        @unlink($tmp);
        tracker_json(['ok' => false, 'error' => 'CSV file is empty.'], 400);
    }
    $normaliseHeader = static function($h): string {
        $h = preg_replace('/^\xEF\xBB\xBF/', '', (string)$h) ?? (string)$h;
        $h = mb_strtolower(trim($h));
        return preg_replace('/[^a-z0-9]+/', '', $h) ?? $h;
    };
    $map = [];
    foreach ($headers as $idx => $header) $map[$normaliseHeader($header)] = $idx;
    $bossIdx = $map['boss'] ?? null;
    $itemIdx = $map['bossitem'] ?? $map['item'] ?? null;
    $collectedIdx = $map['iscollected'] ?? $map['collected'] ?? null;
    if ($bossIdx === null || $itemIdx === null || $collectedIdx === null) {
        fclose($handle);
        @unlink($tmp);
        tracker_json(['ok' => false, 'error' => 'CSV must include Boss, Boss Item and Is collected columns.'], 400);
    }

    [$bossLookup, $itemLookup] = tracker_boss_csv_definition_indexes($definitions);
    $collection = tracker_boss_csv_fetch_collection($pdo, (int)$member['id'], (int)$member['clan_id']);
    $changes = [];
    $invalid = [];
    $line = 1;

    while (($row = fgetcsv($handle)) !== false) {
        $line++;
        if (!is_array($row) || count(array_filter($row, static fn($v) => trim((string)$v) !== '')) === 0) continue;
        $bossRaw = trim((string)($row[$bossIdx] ?? ''));
        $itemRaw = trim((string)($row[$itemIdx] ?? ''));
        $collectedRaw = trim((string)($row[$collectedIdx] ?? ''));
        if ($bossRaw === '' || $itemRaw === '') {
            $invalid[] = "Line {$line}: boss or item is blank";
            continue;
        }
        $requested = tracker_boss_csv_bool($collectedRaw);
        if ($requested === null) {
            $invalid[] = "Line {$line}: Is collected must be yes/no, true/false, or 1/0";
            continue;
        }
        $boss = $bossLookup[tracker_boss_csv_item_key($bossRaw)] ?? null;
        if (!is_array($boss)) {
            $invalid[] = "Line {$line}: unknown boss '{$bossRaw}'";
            continue;
        }
        $bossKey = (string)($boss['key'] ?? '');
        $itemRef = $itemLookup[$bossKey][tracker_boss_csv_item_key($itemRaw)] ?? null;
        if (!is_array($itemRef)) {
            $invalid[] = "Line {$line}: unknown item '{$itemRaw}' for {$bossRaw}";
            continue;
        }
        $item = $itemRef['item'];
        $compound = $bossKey . '|' . (string)($item['key'] ?? '');
        $current = isset($collection[$compound]);
        if ($current === $requested) continue;
        $changes[$compound] = [
            'boss_key' => $bossKey,
            'boss_name' => (string)($boss['name'] ?? ''),
            'item_key' => (string)($item['key'] ?? ''),
            'item_name' => (string)($item['name'] ?? ''),
            'requested_collected' => $requested ? 1 : 0,
            'current_collected' => $current ? 1 : 0,
        ];
    }
    fclose($handle);
    @unlink($tmp);

    if (!$changes) {
        tracker_json([
            'ok' => true,
            'submitted' => false,
            'message' => 'No changed boss drop items were found in the uploaded CSV.',
            'changes' => 0,
            'invalid_rows' => array_slice($invalid, 0, 20),
        ]);
    }

    $submissionUuid = bin2hex(random_bytes(16));
    $note = trim((string)($_POST['submitter_note'] ?? ''));
    if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

    $pdo->beginTransaction();
    try {
        $supersede = $pdo->prepare("UPDATE member_boss_collection_submissions
            SET status = 'superseded', reviewed_at_utc = UTC_TIMESTAMP(3), review_note = 'Superseded by a newer CSV upload', updated_at = CURRENT_TIMESTAMP(3)
            WHERE member_id = :member_id
              AND member_clan_id = :member_clan_id
              AND boss_key = :boss_key
              AND item_key = :item_key
              AND status = 'pending'");
        $insert = $pdo->prepare("INSERT INTO member_boss_collection_submissions
            (submission_uuid, member_id, member_clan_id, member_rsn, boss_key, boss_name, item_key, item_name, requested_collected, current_collected, status, submitter_note)
            VALUES
            (:submission_uuid, :member_id, :member_clan_id, :member_rsn, :boss_key, :boss_name, :item_key, :item_name, :requested_collected, :current_collected, 'pending', :submitter_note)");
        foreach ($changes as $change) {
            $supersede->execute([
                ':member_id' => (int)$member['id'],
                ':member_clan_id' => (int)$member['clan_id'],
                ':boss_key' => $change['boss_key'],
                ':item_key' => $change['item_key'],
            ]);
            $insert->execute([
                ':submission_uuid' => $submissionUuid,
                ':member_id' => (int)$member['id'],
                ':member_clan_id' => (int)$member['clan_id'],
                ':member_rsn' => (string)$member['rsn'],
                ':boss_key' => $change['boss_key'],
                ':boss_name' => $change['boss_name'],
                ':item_key' => $change['item_key'],
                ':item_name' => $change['item_name'],
                ':requested_collected' => (int)$change['requested_collected'],
                ':current_collected' => (int)$change['current_collected'],
                ':submitter_note' => $note !== '' ? $note : null,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        tracker_json(['ok' => false, 'error' => 'Could not save the submission for admin review.'], 500);
    }

    tracker_json([
        'ok' => true,
        'submitted' => true,
        'submission_uuid' => $submissionUuid,
        'changes' => count($changes),
        'invalid_rows' => array_slice($invalid, 0, 20),
        'message' => count($changes) . ' changed boss drop item(s) were submitted for admin review.',
    ]);
}

try {
    $player = tracker_boss_csv_get_param('player', 64);
    if ($player === '') tracker_json(['ok' => false, 'error' => 'Missing player'], 400);

    $pdo = tracker_pdo();
    $member = tracker_boss_csv_find_member($pdo, $player);
    if (!$member) tracker_json(['ok' => false, 'error' => 'Player not found'], 404);

    $definitions = tracker_boss_csv_load_definitions();
    if (empty($definitions['bosses'])) tracker_json(['ok' => false, 'error' => 'Boss log definitions could not be loaded.'], 500);

    $action = strtolower(tracker_boss_csv_get_param('action', 24));
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'download') {
        tracker_boss_csv_download($pdo, $member, $definitions);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
        tracker_boss_csv_upload($pdo, $member, $definitions);
    }

    tracker_json(['ok' => false, 'error' => 'Unsupported boss log CSV action.'], 400);
} catch (Throwable $e) {
    tracker_json(['ok' => false, 'error' => 'Boss drop log CSV request failed.'], 500);
}
