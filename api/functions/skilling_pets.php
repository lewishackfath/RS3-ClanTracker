<?php
declare(strict_types=1);

// Shared with RS3-API/functions/skilling_pets.php. Keep both copies identical.
function sp_normalise(string $value): string
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(['’', '‘', '`', "\xC2\xA0"], ["'", "'", "'", ' '], $value);
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
}

function sp_load_definitions(string $file): array
{
    if (!is_file($file) || !is_readable($file)) throw new RuntimeException('Skilling pet definitions are unavailable.');
    $json = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $pets = [];
    $names = [];
    foreach (($json['pets'] ?? []) as $pet) {
        if (!is_array($pet) || !preg_match('/^[a-z0-9_\-]{1,96}$/D', (string)($pet['key'] ?? ''))) {
            throw new RuntimeException('Invalid skilling pet key.');
        }
        foreach (['skill', 'name', 'icon_name'] as $field) {
            if (!is_string($pet[$field] ?? null) || trim($pet[$field]) === '' || mb_strlen($pet[$field]) > 180) {
                throw new RuntimeException('Invalid skilling pet definition.');
            }
            $pet[$field] = trim($pet[$field]);
        }
        if (isset($pets[$pet['key']])) throw new RuntimeException('Duplicate skilling pet key.');
        $pet['aliases'] = $pet['aliases'] ?? [];
        if (!is_array($pet['aliases'])) throw new RuntimeException('Invalid pet aliases.');
        foreach (array_merge([$pet['name']], $pet['aliases']) as $name) {
            if (!is_string($name) || trim($name) === '') throw new RuntimeException('Invalid pet alias.');
            $normal = sp_normalise($name);
            if (isset($names[$normal]) && $names[$normal] !== $pet['key']) throw new RuntimeException('Ambiguous pet alias.');
            $names[$normal] = $pet['key'];
        }
        $pets[$pet['key']] = $pet;
    }
    if (!$pets) throw new RuntimeException('No skilling pets configured.');
    return ['version' => $json['version'] ?? null, 'pets' => $pets];
}

function sp_ensure_schema(PDO $pdo): void
{
    $types = [];
    $stmt = $pdo->prepare('SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = \'id\'');
    foreach (['members', 'clans', 'member_activities'] as $table) {
        $stmt->execute([$table]);
        $type = (string)$stmt->fetchColumn();
        if (!preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?(?: unsigned)?$/iD', $type)) {
            throw new RuntimeException('Missing or unsupported parent table: ' . $table);
        }
        $types[$table] = $type;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS member_skilling_pets (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        member_id {$types['members']} NOT NULL,
        member_clan_id {$types['clans']} NOT NULL,
        pet_key VARCHAR(96) NOT NULL,
        is_collected TINYINT(1) NOT NULL DEFAULT 1,
        first_seen_utc DATETIME(3) NULL,
        last_seen_utc DATETIME(3) NULL,
        source_activity_id {$types['member_activities']} NULL,
        collection_source VARCHAR(40) NOT NULL DEFAULT 'runemetrics',
        source_note VARCHAR(255) NULL,
        manual_updated_at_utc DATETIME(3) NULL,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        UNIQUE KEY uk_member_skilling_pet (member_id, member_clan_id, pet_key),
        KEY idx_skilling_pet_clan (member_clan_id, member_id),
        CONSTRAINT fk_skilling_pet_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_skilling_pet_clan FOREIGN KEY (member_clan_id) REFERENCES clans(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_skilling_pet_activity FOREIGN KEY (source_activity_id) REFERENCES member_activities(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS member_skilling_pet_submissions (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        submission_uuid CHAR(32) NOT NULL,
        member_id {$types['members']} NOT NULL,
        member_clan_id {$types['clans']} NOT NULL,
        member_rsn VARCHAR(32) NOT NULL,
        pet_key VARCHAR(96) NOT NULL,
        pet_name VARCHAR(180) NOT NULL,
        skill_name VARCHAR(180) NOT NULL,
        requested_collected TINYINT(1) NOT NULL,
        current_collected TINYINT(1) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        submitter_note VARCHAR(255) NULL,
        submitted_at_utc DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        reviewed_by VARCHAR(120) NULL,
        reviewed_at_utc DATETIME(3) NULL,
        review_note VARCHAR(255) NULL,
        KEY idx_pet_submission_uuid (submission_uuid),
        KEY idx_pet_submission_status (status, submitted_at_utc),
        KEY idx_pet_submission_member (member_id, member_clan_id, pet_key),
        CONSTRAINT fk_pet_submission_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_pet_submission_clan FOREIGN KEY (member_clan_id) REFERENCES clans(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function sp_match_activity(array $activity, array $definitions): ?string
{
    foreach (['activity_text', 'activity_details'] as $field) {
        $text = sp_normalise((string)($activity[$field] ?? ''));
        if (!preg_match('/^(?:while skilling,\s*)?i found (.+?)\.?$/uD', $text, $match)) continue;
        foreach ($definitions['pets'] as $key => $pet) {
            foreach (array_merge([$pet['name']], $pet['aliases']) as $name) {
                if ($match[1] === sp_normalise($name)) return $key;
            }
        }
    }
    return null;
}

function sp_fetch_collection(PDO $pdo, int $memberId, int $clanId): array
{
    $stmt = $pdo->prepare('SELECT * FROM member_skilling_pets WHERE member_id = ? AND member_clan_id = ?');
    $stmt->execute([$memberId, $clanId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $out[$row['pet_key']] = $row;
    return $out;
}

function sp_sync(PDO $pdo, int $memberId, int $clanId, array $definitions): array
{
    // Scan stored history, not just the currently displayed activity page. The
    // exact parser below rejects generic drops, boss pets and partial pet names.
    $stmt = $pdo->prepare("SELECT id, activity_date_utc, activity_text, activity_details
        FROM member_activities WHERE member_id = ? AND member_clan_id = ?
        AND (activity_text LIKE '%I found %' OR activity_details LIKE '%I found %')
        ORDER BY activity_date_utc, id");
    $stmt->execute([$memberId, $clanId]);
    $found = [];
    $matched = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = sp_match_activity($row, $definitions);
        if ($key === null) continue;
        $matched++;
        $date = $row['activity_date_utc'];
        if (!isset($found[$key])) $found[$key] = ['first' => $date, 'last' => $date, 'id' => $row['id']];
        if ($found[$key]['first'] === null) $found[$key]['first'] = $date;
        if ($date !== null) $found[$key]['last'] = $date;
    }
    $upsert = $pdo->prepare("INSERT INTO member_skilling_pets
        (member_id, member_clan_id, pet_key, first_seen_utc, last_seen_utc, source_activity_id)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            first_seen_utc = COALESCE(LEAST(first_seen_utc, VALUES(first_seen_utc)), first_seen_utc, VALUES(first_seen_utc)),
            last_seen_utc = COALESCE(GREATEST(last_seen_utc, VALUES(last_seen_utc)), last_seen_utc, VALUES(last_seen_utc)),
            source_activity_id = COALESCE(source_activity_id, VALUES(source_activity_id))");
    foreach ($found as $key => $row) $upsert->execute([$memberId, $clanId, $key, $row['first'], $row['last'], $row['id']]);
    // No count increment or overwrite of is_collected: replay is idempotent and
    // an explicit admin/accepted CSV correction survives historical rescans.
    return ['matched_activities' => $matched, 'matched_pets' => count($found)];
}

function sp_build_log(PDO $pdo, int $memberId, int $clanId, string $file): array
{
    $out = ['available' => false, 'error' => null, 'pets' => [], 'summary' => ['found_pets' => 0, 'total_pets' => 0]];
    try {
        $definitions = sp_load_definitions($file);
    } catch (Throwable $e) {
        $out['error'] = 'Skilling pet definitions could not be loaded.';
        return $out;
    }
    $stored = [];
    try {
        $out['sync'] = sp_sync($pdo, $memberId, $clanId, $definitions);
        $stored = sp_fetch_collection($pdo, $memberId, $clanId);
        $out['available'] = true;
    } catch (Throwable $e) {
        $out['error'] = 'Skilling pet storage is unavailable. Run the updated database bootstrap, then reload.';
    }
    foreach ($definitions['pets'] as $key => $pet) {
        $state = $stored[$key] ?? [];
        $found = (int)($state['is_collected'] ?? 0) === 1;
        $out['pets'][] = $pet + ['found' => $found, 'first_seen_utc' => $state['first_seen_utc'] ?? null,
            'collection_source' => $state['collection_source'] ?? null];
        $out['summary']['found_pets'] += (int)$found;
    }
    $out['summary']['total_pets'] = count($out['pets']);
    $out['version'] = $definitions['version'];
    return $out;
}

function sp_csv_bool(string $value): ?bool
{
    $value = sp_normalise($value);
    if (in_array($value, ['1', 'y', 'yes', 'true', 'collected', 'found'], true)) return true;
    if (in_array($value, ['0', 'n', 'no', 'false', 'missing', 'not collected', 'uncollected'], true)) return false;
    return null;
}

// Parse the whole file before writing. Invalid or contradictory rows reject the
// upload atomically, so a typo cannot silently erase an existing collection.
function sp_parse_csv($handle, array $definitions): array
{
    $headers = fgetcsv($handle, 0, ',', '"', '');
    if (!is_array($headers)) throw new InvalidArgumentException('CSV file is empty.');
    $map = [];
    foreach ($headers as $index => $header) {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header) ?? '';
        $normal = preg_replace('/[^a-z0-9]/', '', sp_normalise($header));
        if (isset($map[$normal])) throw new InvalidArgumentException('CSV has duplicate column names.');
        $map[$normal] = $index;
    }
    foreach (['skill', 'pet', 'iscollected'] as $header) {
        if (!isset($map[$header])) throw new InvalidArgumentException('CSV must include Skill, Pet and Is collected columns.');
    }
    $lookup = [];
    foreach ($definitions['pets'] as $key => $pet) {
        foreach (array_merge([$key, $pet['name'], $pet['icon_name']], $pet['aliases']) as $name) {
            $lookup[sp_normalise($pet['skill'])][sp_normalise($name)] = $key;
        }
    }
    $requested = [];
    $line = 1;
    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $line++;
        if (!array_filter($row, static fn($v) => trim((string)$v) !== '')) continue;
        $skill = sp_normalise((string)($row[$map['skill']] ?? ''));
        $name = sp_normalise((string)($row[$map['pet']] ?? ''));
        $key = $lookup[$skill][$name] ?? null;
        $value = sp_csv_bool((string)($row[$map['iscollected']] ?? ''));
        if ($key === null) throw new InvalidArgumentException("Line {$line}: unknown skill/pet combination.");
        if ($value === null) throw new InvalidArgumentException("Line {$line}: Is collected must be yes/no, true/false, or 1/0.");
        if (isset($requested[$key]) && $requested[$key] !== $value) throw new InvalidArgumentException("Line {$line}: conflicting duplicate pet.");
        $requested[$key] = $value;
    }
    return $requested;
}

function sp_write_csv($handle, array $definitions, array $collection): void
{
    fputcsv($handle, ['Skill', 'Pet', 'Is collected'], ',', '"', '');
    foreach ($definitions['pets'] as $key => $pet) {
        fputcsv($handle, [$pet['skill'], $pet['name'], !empty($collection[$key]['is_collected']) ? 'yes' : 'no'], ',', '"', '');
    }
}

function sp_lock_member(PDO $pdo, int $memberId, int $clanId): array
{
    $stmt = $pdo->prepare('SELECT id, clan_id, rsn FROM members WHERE id = ? AND clan_id = ? FOR UPDATE');
    $stmt->execute([$memberId, $clanId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Player membership has changed. Reload and try again.');
    return $row;
}

function sp_submit(PDO $pdo, array $member, array $definitions, array $requested, string $note): array
{
    $uuid = bin2hex(random_bytes(16));
    $pdo->beginTransaction();
    try {
        $member = sp_lock_member($pdo, (int)$member['id'], (int)$member['clan_id']);
        $collection = sp_fetch_collection($pdo, (int)$member['id'], (int)$member['clan_id']);
        $supersede = $pdo->prepare("UPDATE member_skilling_pet_submissions SET status = 'superseded', reviewed_at_utc = UTC_TIMESTAMP(3), review_note = 'Superseded by a newer CSV upload'
            WHERE member_id = ? AND member_clan_id = ? AND pet_key = ? AND status = 'pending'");
        $insert = $pdo->prepare("INSERT INTO member_skilling_pet_submissions
            (submission_uuid, member_id, member_clan_id, member_rsn, pet_key, pet_name, skill_name, requested_collected, current_collected, submitter_note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $count = 0;
        foreach ($requested as $key => $value) {
            if (!isset($definitions['pets'][$key]) || !is_bool($value)) throw new InvalidArgumentException('Invalid requested pet.');
            $pet = $definitions['pets'][$key];
            $current = !empty($collection[$key]['is_collected']);
            $supersede->execute([$member['id'], $member['clan_id'], $key]);
            if ($current === $value) continue;
            $insert->execute([$uuid, $member['id'], $member['clan_id'], $member['rsn'], $key, $pet['name'], $pet['skill'],
                (int)$value, (int)$current, mb_substr(trim($note), 0, 255) ?: null]);
            $count++;
        }
        $pdo->commit();
        return ['submitted' => $count > 0, 'changes' => $count, 'submission_uuid' => $count ? $uuid : null,
            'message' => $count ? "{$count} skilling pet change(s) submitted for admin review." : 'No new changes to submit. Any older pending requests for these pets were superseded.'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function sp_set_state(PDO $pdo, int $memberId, int $clanId, string $key, bool $value, string $source, string $note): void
{
    $stmt = $pdo->prepare("INSERT INTO member_skilling_pets
        (member_id, member_clan_id, pet_key, is_collected, collection_source, source_note, manual_updated_at_utc)
        VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3))
        ON DUPLICATE KEY UPDATE is_collected = VALUES(is_collected), collection_source = VALUES(collection_source),
            source_note = VALUES(source_note), manual_updated_at_utc = UTC_TIMESTAMP(3)");
    $stmt->execute([$memberId, $clanId, $key, (int)$value, $source, mb_substr($note, 0, 255)]);
}

function sp_save_collection(PDO $pdo, array $member, array $definitions, array $selected): int
{
    foreach ($selected as $key) {
        if (!is_string($key) || !isset($definitions['pets'][$key])) throw new InvalidArgumentException('Unknown pet selection.');
    }
    $pdo->beginTransaction();
    try {
        sp_lock_member($pdo, (int)$member['id'], (int)$member['clan_id']);
        $collection = sp_fetch_collection($pdo, (int)$member['id'], (int)$member['clan_id']);
        $changed = 0;
        foreach ($definitions['pets'] as $key => $pet) {
            $value = in_array($key, $selected, true);
            if ($value === !empty($collection[$key]['is_collected'])) continue;
            sp_set_state($pdo, (int)$member['id'], (int)$member['clan_id'], $key, $value, 'manual_admin', 'Updated in RS3 API admin');
            $stmt = $pdo->prepare("UPDATE member_skilling_pet_submissions SET status = 'superseded', reviewed_at_utc = UTC_TIMESTAMP(3), review_note = 'Superseded by an admin edit'
                WHERE member_id = ? AND member_clan_id = ? AND pet_key = ? AND status = 'pending'");
            $stmt->execute([$member['id'], $member['clan_id'], $key]);
            $changed++;
        }
        $pdo->commit();
        return $changed;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function sp_review(PDO $pdo, string $uuid, string $decision, string $reviewer, array $definitions): int
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $uuid) || !in_array($decision, ['accepted', 'declined'], true)) {
        throw new InvalidArgumentException('Invalid submission review.');
    }
    $stmt = $pdo->prepare('SELECT member_id, member_clan_id FROM member_skilling_pet_submissions WHERE submission_uuid = ? LIMIT 1');
    $stmt->execute([$uuid]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) return 0;
    $pdo->beginTransaction();
    try {
        sp_lock_member($pdo, (int)$member['member_id'], (int)$member['member_clan_id']);
        $stmt = $pdo->prepare("SELECT * FROM member_skilling_pet_submissions WHERE submission_uuid = ? AND status = 'pending' FOR UPDATE");
        $stmt->execute([$uuid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if ($decision !== 'accepted') continue;
            if (!isset($definitions['pets'][$row['pet_key']])) throw new RuntimeException('Pet is no longer configured. Decline this submission and upload a new CSV.');
            sp_set_state($pdo, (int)$row['member_id'], (int)$row['member_clan_id'], $row['pet_key'], (bool)$row['requested_collected'],
                'player_csv', 'Accepted CSV submission ' . substr($uuid, 0, 8));
        }
        $update = $pdo->prepare("UPDATE member_skilling_pet_submissions SET status = ?, reviewed_by = ?, reviewed_at_utc = UTC_TIMESTAMP(3), review_note = 'Reviewed in RS3 API admin' WHERE submission_uuid = ? AND status = 'pending'");
        $update->execute([$decision, mb_substr($reviewer, 0, 120), $uuid]);
        $pdo->commit();
        return count($rows);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
