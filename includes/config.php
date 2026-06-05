<?php
// includes/config.php
declare(strict_types=1);

require_once __DIR__ . '/../api/_env.php';

// Match the API loader: prefer an .env one level above web root, then local fallback.
tracker_load_env(dirname(__DIR__, 2) . '/.env');
tracker_load_env(dirname(__DIR__) . '/.env');

function tracker_env_value(string $key, string $default = ''): string {
  $value = getenv($key);
  if ($value === false || $value === '') return $default;
  return trim((string)$value);
}

function tracker_env_bool(string $key, bool $default = false): bool {
  $value = getenv($key);
  if ($value === false || trim((string)$value) === '') return $default;
  $normalised = strtolower(trim((string)$value));
  return in_array($normalised, ['1', 'true', 'yes', 'on'], true);
}

function tracker_brand_config(): array {
  $brandName = tracker_env_value('TRACKER_BRAND_NAME', 'Clan Tracker');
  $siteUrl = tracker_env_value('TRACKER_BRAND_HOME_URL', 'index');

  return [
    'clan_id' => tracker_env_value('TRACKER_CLAN_ID', ''),
    'name' => $brandName,
    'short_name' => tracker_env_value('TRACKER_BRAND_SHORT_NAME', $brandName),
    'subtitle' => tracker_env_value('TRACKER_BRAND_SUBTITLE', 'Clan overview & member lookup'),
    'logo_url' => tracker_env_value('TRACKER_BRAND_LOGO_URL', 'assets/hit-media.png'),
    'home_url' => $siteUrl,
    'domain' => tracker_env_value('TRACKER_BRAND_DOMAIN', parse_url($siteUrl, PHP_URL_HOST) ?: 'tracker.local'),
    'footer_title' => tracker_env_value('TRACKER_BRAND_FOOTER_TITLE', $brandName),
    'developer_logo_url' => tracker_env_value('TRACKER_DEVELOPER_LOGO_URL', 'assets/hit-media.png'),
    'developer_name' => tracker_env_value('TRACKER_DEVELOPER_NAME', 'HIT Media'),
    'bingo_url' => tracker_env_value('TRACKER_BRAND_BINGO_URL', ''),
    'show_bingo_link' => tracker_env_bool('TRACKER_SHOW_BINGO_LINK', false),
  ];
}

function tracker_public_js_config(): array {
  $brand = tracker_brand_config();
  return [
    'clanId' => $brand['clan_id'],
    'brandName' => $brand['name'],
    'brandShortName' => $brand['short_name'],
    'brandSubtitle' => $brand['subtitle'],
  ];
}
