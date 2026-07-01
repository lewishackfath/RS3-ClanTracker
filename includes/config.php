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
  $backgroundUrl = tracker_env_value('TRACKER_BRAND_BACKGROUND_URL', '');
  if ($backgroundUrl === '') {
    $backgroundUrl = tracker_env_value('TRACKER_BACKGROUND_IMAGE_URL', 'assets/bg.png');
  }

  return [
    'clan_id' => tracker_env_value('TRACKER_CLAN_ID', ''),
    'name' => $brandName,
    'short_name' => tracker_env_value('TRACKER_BRAND_SHORT_NAME', $brandName),
    'subtitle' => tracker_env_value('TRACKER_BRAND_SUBTITLE', 'Clan overview & member lookup'),
    'logo_url' => tracker_env_value('TRACKER_BRAND_LOGO_URL', 'assets/hit-media.png'),
    'background_url' => $backgroundUrl,
    'home_url' => $siteUrl,
    'domain' => tracker_env_value('TRACKER_BRAND_DOMAIN', parse_url($siteUrl, PHP_URL_HOST) ?: 'tracker.local'),
    'footer_title' => tracker_env_value('TRACKER_BRAND_FOOTER_TITLE', $brandName),
    'developer_logo_url' => tracker_env_value('TRACKER_DEVELOPER_LOGO_URL', 'assets/hit-media.png'),
    'developer_name' => tracker_env_value('TRACKER_DEVELOPER_NAME', 'HIT Media'),
    'bingo_url' => tracker_env_value('TRACKER_BRAND_BINGO_URL', ''),
    'show_bingo_link' => tracker_env_bool('TRACKER_SHOW_BINGO_LINK', false),
  ];
}

function tracker_css_background_style(array $brand): string {
  $url = trim((string)($brand['background_url'] ?? ''));
  if ($url === '') return '';

  // Keep this safe for inline CSS while still supporting normal relative paths and https URLs.
  if (preg_match('/[\x00-\x1F\x7F]/', $url)) return '';
  $scheme = parse_url($url, PHP_URL_SCHEME);
  if ($scheme !== null && $scheme !== '' && !in_array(strtolower($scheme), ['http', 'https'], true)) {
    return '';
  }

  $cssUrl = str_replace(['\\', '"'], ['\\\\', '\\"'], $url);
  return '--tracker-background-image: url("' . $cssUrl . '");';
}

function tracker_body_style_attr(array $brand): string {
  $style = tracker_css_background_style($brand);
  if ($style === '') return '';
  return ' style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '"';
}

function tracker_public_js_config(): array {
  $brand = tracker_brand_config();
  return [
    'clanId' => $brand['clan_id'],
    'brandName' => $brand['name'],
    'brandShortName' => $brand['short_name'],
    'brandSubtitle' => $brand['subtitle'],
    'brandLogoUrl' => $brand['logo_url'],
  ];
}
