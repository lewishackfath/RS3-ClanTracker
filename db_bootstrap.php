<?php
declare(strict_types=1);

/**
 * db_bootstrap.php (schema ensure, FK-safe)
 *
 * Key goals:
 * - No external .sql dependency
 * - Always ensures ALL tables exist (CREATE TABLE IF NOT EXISTS)
 * - Works even if a DB is partially created OR has older column types
 * - Avoids FK errno 150 by:
 *     - ensuring parent tables are InnoDB
 *     - matching child FK column types to the parent PK COLUMN_TYPE
 *
 * Env:
 *   BOOTSTRAP_FORCE=1  -> drop known tables and recreate
 */

if (!defined('DB_BOOTSTRAP_LOADED')) {
    define('DB_BOOTSTRAP_LOADED', true);
}

date_default_timezone_set('UTC');

function dbb_out(string $msg): void {
    if (PHP_SAPI === 'cli') {
        echo $msg . PHP_EOL;
    } else {
        if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
        echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "<br>\n";
        @ob_flush(); @flush();
    }
}


function dbb_get_pdo(array $config): PDO {
    if (!isset($config['db']) || !is_array($config['db'])) {
        throw new RuntimeException("Invalid config: missing db[]");
    }

    $db = $config['db'];
    foreach (['host','name','user','pass'] as $k) {
        if (!array_key_exists($k, $db)) {
            throw new RuntimeException("DB config missing key: {$k}");
        }
    }

    $charset = (string)($db['charset'] ?? 'utf8mb4');
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        (string)$db['host'],
        (string)$db['name'],
        $charset
    );

    $pdo = new PDO(
        $dsn,
        (string)$db['user'],
        (string)$db['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    return $pdo;
}
function dbb_get_table_engine(PDO $pdo, string $table): ?string {
    $st = $pdo->prepare("
        SELECT ENGINE
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :t
        LIMIT 1
    ");
    $st->execute([':t' => $table]);
    $e = $st->fetchColumn();
    return $e ? (string)$e : null;
}

function dbb_ensure_innodb(PDO $pdo, string $table): void {
    $engine = dbb_get_table_engine($pdo, $table);
    if ($engine && strtoupper($engine) !== 'INNODB') {
        dbb_out("DB bootstrap: converting {$table} to InnoDB (was {$engine})");
        $pdo->exec("ALTER TABLE `{$table}` ENGINE=InnoDB");
    }
}

function dbb_get_column_type(PDO $pdo, string $table, string $column): ?string {
    $st = $pdo->prepare("
        SELECT COLUMN_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :t
          AND COLUMN_NAME = :c
        LIMIT 1
    ");
    $st->execute([':t' => $table, ':c' => $column]);
    $t = $st->fetchColumn();
    return $t ? (string)$t : null;
}

function dbb_tables_present(PDO $pdo): array {
    $stmt = $pdo->query("SHOW TABLES");
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function dbb_drop_known_tables(PDO $pdo): void {
    $dropOrder = [
        'member_boss_collection_log',
        'member_poll_state',
        'member_rm_quests',
        'member_hiscores_lite',
        'member_xp_snapshots',
        'member_activities',
        'member_caps',
        'member_citadel_visits',
        'activity_announcement_rules',
        'members',
        'clans',
    ];

    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    foreach ($dropOrder as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
}

/**
 * Ensure all tables exist, with FK-safe types.
 */
function dbb_create_tables(PDO $pdo): void
{
    // 1) clans (parent)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `clans` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(100) NOT NULL,
        `timezone` VARCHAR(64) NOT NULL DEFAULT 'UTC',
        `reset_weekday` TINYINT NOT NULL DEFAULT 0,
        `reset_time` TIME NOT NULL DEFAULT '00:00:00',
        `max_rank_by_capping` VARCHAR(40) NULL,
        `discord_guild_id` VARCHAR(64) NULL,
        `discord_log_channel_id` VARCHAR(64) NULL,
        `discord_ping_channel_id` VARCHAR(64) NULL,
        `discord_announcement_channel_id` VARCHAR(64) NULL,
        `discord_ping_role_ids_json` JSON NULL,
        `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        `inactive_at` DATETIME(3) NULL,
        PRIMARY KEY (`id`),
        KEY `idx_clans_enabled` (`is_enabled`,`inactive_at`),
        UNIQUE KEY `uk_clans_guild` (`discord_guild_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    dbb_ensure_innodb($pdo, 'clans');

    $clanIdType = dbb_get_column_type($pdo, 'clans', 'id') ?: 'INT';

    // 2) members (child of clans, parent of others)
    //    Match clan_id type to clans.id exactly to avoid errno 150.
    $pdo->exec("CREATE TABLE IF NOT EXISTS `members` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `clan_id` {$clanIdType} NOT NULL,
        `rsn` VARCHAR(32) NOT NULL,
        `rsn_normalised` VARCHAR(32) NOT NULL,
        `rank_name` VARCHAR(40) NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `is_private` TINYINT(1) NOT NULL DEFAULT 0,
        `private_since_utc` DATETIME(3) NULL,
        `last_promotion_at_utc` DATETIME(3) NULL,
        `last_sync` DATETIME(3) NULL,
        `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_members_clan_rsn_norm` (`clan_id`,`rsn_normalised`),
        KEY `idx_members_clan_active` (`clan_id`,`is_active`),
        CONSTRAINT `fk_members_clan` FOREIGN KEY (`clan_id`) REFERENCES `clans`(`id`)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    dbb_ensure_innodb($pdo, 'members');

    $memberIdType = dbb_get_column_type($pdo, 'members', 'id') ?: 'INT';

    // 3) activity_announcement_rules (optional clan_id)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `activity_announcement_rules` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `clan_id` {$clanIdType} NULL,
        `purpose` VARCHAR(64) NOT NULL,
        `match_kind` VARCHAR(32) NOT NULL,
        `match_value` VARCHAR(255) NOT NULL,
        `message_template` TEXT NULL,
        `discord_announcement_channel_id` VARCHAR(64) NULL,
        `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        PRIMARY KEY (`id`),
        KEY `idx_rules_enabled` (`is_enabled`),
        KEY `idx_rules_clan_purpose` (`clan_id`,`purpose`),
        CONSTRAINT `fk_rules_clan` FOREIGN KEY (`clan_id`) REFERENCES `clans`(`id`)
            ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    dbb_ensure_innodb($pdo, 'activity_announcement_rules');

    $ruleIdType = dbb_get_column_type($pdo, 'activity_announcement_rules', 'id') ?: 'INT';

    // 4) member_activities
    $pdo->exec("CREATE TABLE IF NOT EXISTS `member_activities` (
        `id` BIGINT NOT NULL AUTO_INCREMENT,
        `member_id` {$memberIdType} NOT NULL,
        `member_clan_id` {$clanIdType} NOT NULL,
        `rule_id` {$ruleIdType} NULL,
        `activity_hash` CHAR(64) NOT NULL,
        `activity_date_utc` DATETIME(3) NOT NULL,
        `activity_text` VARCHAR(255) NOT NULL,
        `activity_details` TEXT NULL,
        `is_announced` TINYINT(1) NOT NULL DEFAULT 0,
        `announced_at` DATETIME(3) NULL,
        `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_member_activity_hash` (`member_id`,`activity_hash`),
        KEY `idx_activities_clan_date` (`member_clan_id`,`activity_date_utc`),
        KEY `idx_activities_rule` (`rule_id`),
        KEY `idx_activities_unannounced` (`is_announced`,`activity_date_utc`),
        CONSTRAINT `fk_activities_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_activities_clan` FOREIGN KEY (`member_clan_id`) REFERENCES `clans`(`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_activities_rule` FOREIGN KEY (`rule_id`) REFERENCES `activity_announcement_rules`(`id`)
            ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    dbb_ensure_innodb($pdo, 'member_activities');
    $activityIdType = dbb_get_column_type($pdo, 'member_activities', 'id') ?: 'BIGINT';

    // 5) member_boss_collection_log
    $pdo->exec("CREATE TABLE IF NOT EXISTS `member_boss_collection_log` (
        `id` BIGINT NOT NULL AUTO_INCREMENT,
        `member_id` {$memberIdType} NOT NULL,
        `member_clan_id` {$clanIdType} NOT NULL,
        `boss_key` VARCHAR(96) NOT NULL,
        `boss_name` VARCHAR(120) NOT NULL,
        `item_key` VARCHAR(140) NOT NULL,
        `item_name` VARCHAR(180) NOT NULL,
        `drop_count` INT NOT NULL DEFAULT 0,
        `first_seen_utc` DATETIME(3) NULL,
        `last_seen_utc` DATETIME(3) NULL,
        `source_activity_id` {$activityIdType} NULL,
        `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_member_boss_item` (`member_id`,`boss_key`,`item_key`),
        KEY `idx_boss_log_member_clan` (`member_clan_id`,`member_id`),
        KEY `idx_boss_log_boss` (`boss_key`),
        KEY `idx_boss_log_item` (`item_key`),
        KEY `idx_boss_log_source_activity` (`source_activity_id`),
        CONSTRAINT `fk_boss_log_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_boss_log_clan` FOREIGN KEY (`member_clan_id`) REFERENCES `clans`(`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_boss_log_activity` FOREIGN KEY (`source_activity_id`) REFERENCES `member_activities`(`id`)
            ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    dbb_ensure_innodb($pdo, 'member_boss_collection_log');

    // 6) member_xp_snapshots
    $pdo->exec("CREATE TABLE IF NOT EXISTS `member_xp_snapshots` (
        `id` BIGINT NOT NULL AUTO_INCREMENT,
        `member_id` {$memberIdType} NOT NULL,
        `total_xp` BIGINT NOT NULL,
        `skills_json` JSON NOT NULL,
        `snapshot_hash` CHAR(64) NOT NULL,
        `captured_at_utc` DATETIME(3) NOT NULL,
        `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_member_snapshot_hash` (`member_id`,`snapshot_hash`),
        KEY `idx_snapshots_member_time` (`member_id`,`captured_at_utc`),
        CONSTRAINT `fk_snapshots_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 7) member_hiscores_lite
    $pdo->exec("CREATE TABLE IF NOT EXISTS `member_hiscores_lite` (
        `id` BIGINT NOT NULL AUTO_INCREMENT,
        `member_id` {$memberIdType} NOT NULL,
        `member_clan_id` {$clanIdType} NOT NULL,
        `json_data` JSON NOT NULL,
        `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_hiscores_lite_member_clan` (`member_id`,`member_clan_id`),
        KEY `idx_hiscores_lite_clan_updated` (`member_clan_id`,`updated_at`),
        CONSTRAINT `fk_hiscores_lite_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_hiscores_lite_clan` FOREIGN KEY (`member_clan_id`) REFERENCES `clans`(`id`)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 8) member_rm_quests
    $pdo->exec("CREATE TABLE IF NOT EXISTS `member_rm_quests` (
        `id` BIGINT NOT NULL AUTO_INCREMENT,
        `member_id` {$memberIdType} NOT NULL,
        `member_clan_id` {$clanIdType} NOT NULL,
        `json_data` JSON NOT NULL,
        `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_rm_quests_member_clan` (`member_id`,`member_clan_id`),
        KEY `idx_rm_quests_clan_updated` (`member_clan_id`,`updated_at`),
        CONSTRAINT `fk_rm_quests_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_rm_quests_clan` FOREIGN KEY (`member_clan_id`) REFERENCES `clans`(`id`)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 9) member_citadel_visits
    $pdo->exec("CREATE TABLE IF NOT EXISTS `member_citadel_visits` (
        `id` BIGINT NOT NULL AUTO_INCREMENT,
        `clan_id` {$clanIdType} NOT NULL,
        `member_id` {$memberIdType} NOT NULL,
        `cap_week_start_utc` DATETIME(3) NOT NULL,
        `cap_week_end_utc` DATETIME(3) NOT NULL,
        `visited_at_utc` DATETIME(3) NOT NULL,
        `rule_id` {$ruleIdType} NULL,
        `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_visit_member_week` (`member_id`,`cap_week_start_utc`),
        KEY `idx_visits_clan_week` (`clan_id`,`cap_week_start_utc`),
        KEY `idx_visits_time` (`visited_at_utc`),
        CONSTRAINT `fk_visits_clan` FOREIGN KEY (`clan_id`) REFERENCES `clans`(`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_visits_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_visits_rule` FOREIGN KEY (`rule_id`) REFERENCES `activity_announcement_rules`(`id`)
            ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 10) member_caps
    $pdo->exec("CREATE TABLE IF NOT EXISTS `member_caps` (
        `id` BIGINT NOT NULL AUTO_INCREMENT,
        `clan_id` {$clanIdType} NOT NULL,
        `member_id` {$memberIdType} NOT NULL,
        `cap_week_start_utc` DATETIME(3) NOT NULL,
        `cap_week_end_utc` DATETIME(3) NOT NULL,
        `capped_at_utc` DATETIME(3) NOT NULL,
        `rule_id` {$ruleIdType} NULL,
        `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_cap_member_week` (`member_id`,`cap_week_start_utc`),
        KEY `idx_caps_clan_week` (`clan_id`,`cap_week_start_utc`),
        KEY `idx_caps_time` (`capped_at_utc`),
        CONSTRAINT `fk_caps_clan` FOREIGN KEY (`clan_id`) REFERENCES `clans`(`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_caps_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_caps_rule` FOREIGN KEY (`rule_id`) REFERENCES `activity_announcement_rules`(`id`)
            ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 11) member_poll_state
    $pdo->exec("CREATE TABLE IF NOT EXISTS `member_poll_state` (
        `member_id` {$memberIdType} NOT NULL,
        `clan_id` {$clanIdType} NOT NULL,
        `next_poll_at_utc` DATETIME(3) NULL,
        `last_poll_at_utc` DATETIME(3) NULL,
        `consecutive_429` INT NOT NULL DEFAULT 0,
        `last_http_status` INT NULL,
        `last_error` TEXT NULL,
        `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        PRIMARY KEY (`member_id`,`clan_id`),
        KEY `idx_poll_next` (`next_poll_at_utc`),
        CONSTRAINT `fk_poll_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_poll_clan` FOREIGN KEY (`clan_id`) REFERENCES `clans`(`id`)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


}

/**
 * Safe, idempotent migrations.
 */
function dbb_apply_schema_migrations(PDO $pdo): void
{
    // Ensure nullable clan_id on rules even if table existed with NOT NULL
    try {
        $nullable = $pdo->query("
            SELECT IS_NULLABLE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'activity_announcement_rules'
              AND COLUMN_NAME = 'clan_id'
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        if ($nullable && strtoupper((string)$nullable['IS_NULLABLE']) !== 'YES') {
            $pdo->exec("ALTER TABLE activity_announcement_rules MODIFY clan_id INT NULL");
        }
    } catch (Throwable $e) {}

    // Ensure clans.max_rank_by_capping VARCHAR(40)
    try {
        $col = $pdo->query("
            SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'clans'
              AND COLUMN_NAME = 'max_rank_by_capping'
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        if ($col) {
            $dt = strtolower((string)($col['DATA_TYPE'] ?? ''));
            $ln = (int)($col['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
            if ($dt !== 'varchar' || $ln < 40) {
                $pdo->exec("ALTER TABLE clans MODIFY max_rank_by_capping VARCHAR(40) NULL");
            }
        }
    } catch (Throwable $e) {}

    // Ensure clans.discord_guild_id VARCHAR(64)
    try {
        $col = $pdo->query("
            SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'clans'
              AND COLUMN_NAME = 'discord_guild_id'
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        if ($col) {
            $dt = strtolower((string)($col['DATA_TYPE'] ?? ''));
            $ln = (int)($col['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
            if ($dt !== 'varchar' || $ln < 64) {
                $pdo->exec("ALTER TABLE clans MODIFY discord_guild_id VARCHAR(64) NULL");
            }
        }
    } catch (Throwable $e) {}

    // Ensure member_poll_state.last_http_status INT NULL
    try {
        $col = $pdo->query("
            SELECT DATA_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'member_poll_state'
              AND COLUMN_NAME = 'last_http_status'
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        if ($col && strtolower((string)$col['DATA_TYPE']) !== 'int') {
            $pdo->exec("ALTER TABLE member_poll_state MODIFY last_http_status INT NULL");
        }
    } catch (Throwable $e) {}

// Ensure members.is_private + members.private_since_utc exist
try {
    $col = $pdo->query("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'members'
          AND COLUMN_NAME = 'is_private'
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        $pdo->exec("ALTER TABLE members ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Throwable $e) {}

try {
    $col = $pdo->query("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'members'
          AND COLUMN_NAME = 'private_since_utc'
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        $pdo->exec("ALTER TABLE members ADD COLUMN private_since_utc DATETIME(3) NULL");
    }
} catch (Throwable $e) {}

// Ensure members.last_promotion_at_utc exists (set when roster sync detects rank increases)
try {
    $col = $pdo->query("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'members'
          AND COLUMN_NAME = 'last_promotion_at_utc'
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        $pdo->exec("ALTER TABLE members ADD COLUMN last_promotion_at_utc DATETIME(3) NULL");
    }
} catch (Throwable $e) {}


}

function dbb_bootstrap_schema(PDO $pdo, bool $force = false): void
{
    $force = $force || ((string)getenv('BOOTSTRAP_FORCE') === '1');

    if ($force) {
        dbb_out("DB bootstrap: BOOTSTRAP_FORCE=1 set — dropping known tables...");
        dbb_drop_known_tables($pdo);
    }

    dbb_create_tables($pdo);
    dbb_apply_schema_migrations($pdo);

    $existing = dbb_tables_present($pdo);
    dbb_out("DB bootstrap: schema ensured. Tables present: " . count($existing));
}
