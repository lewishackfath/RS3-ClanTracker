<?php
declare(strict_types=1);

/**
 * asset_icon.php
 *
 * Resolves local tracker icons server-side so the browser does not have to try
 * many possible filenames/extensions and fill the console with 404s.
 *
 * Usage:
 *   /api/asset_icon.php?type=skill&name=Defence
 *   /api/asset_icon.php?type=monster&name=General%20Graardor
 *   /api/asset_icon.php?type=item&name=Bandos%20boots
 */

$type = strtolower(trim((string)($_GET['type'] ?? '')));
$name = trim((string)($_GET['name'] ?? ''));
$path = trim((string)($_GET['path'] ?? ''));

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    http_response_code(500);
    exit;
}

$config = [
    'skill' => [
        'dir' => $root . '/assets/skills',
        'default' => $root . '/assets/skills/_default.png',
    ],
    'monster' => [
        'dir' => $root . '/assets/activity/monsters',
        'default' => $root . '/assets/activity/default.png',
    ],
    'activity' => [
        'dir' => $root . '/assets/activity',
        'default' => $root . '/assets/activity/default.png',
    ],
    'item' => [
        'dir' => $root . '/assets/items',
        'default' => $root . '/assets/activity/default.png',
    ],
];

if (!isset($config[$type])) {
    $type = 'activity';
}

$dir = $config[$type]['dir'];
$defaultFile = $config[$type]['default'];
$allowedExts = ['png', 'webp', 'jpg', 'jpeg', 'svg'];

require_once __DIR__ . '/functions/icon_files.php';

$candidateNames = [];

if ($path !== '') {
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#^/+#', '', $path) ?? $path;
    $path = preg_replace('#^\./+#', '', $path) ?? $path;
    $baseName = basename($path);
    add_candidate_names($candidateNames, $baseName);

    $fullPath = realpath($root . '/' . $path);
    if ($fullPath !== false && str_starts_with($fullPath, $root . DIRECTORY_SEPARATOR)) {
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExts, true) && is_file($fullPath) && filesize($fullPath) > 0) {
            serve_icon($fullPath);
        }
    }
}

if ($name !== '') {
    add_candidate_names($candidateNames, $name);
}

$found = find_icon_file($dir, $candidateNames, $allowedExts);
if ($found !== null) {
    serve_icon($found);
}

if (is_file($defaultFile) && filesize($defaultFile) > 0) {
    serve_icon($defaultFile, true);
}

// Last-resort 404 only if the default asset is missing from the codebase.
http_response_code(404);
