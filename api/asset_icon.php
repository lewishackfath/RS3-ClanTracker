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

function icon_mime_type(string $file): string {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return match ($ext) {
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'jpg', 'jpeg' => 'image/jpeg',
        default => 'image/png',
    };
}

function serve_icon(string $file): void {
    header('Content-Type: ' . icon_mime_type($file));
    header('Cache-Control: public, max-age=86400');
    $mtime = (int)@filemtime($file);
    if ($mtime > 0) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    }
    readfile($file);
    exit;
}

function icon_file_key(string $value): string {
    $value = trim($value);
    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower($value, 'UTF-8');
    } else {
        $value = strtolower($value);
    }
    $value = preg_replace('/[\'\"’]/u', '', $value) ?? $value;
    $value = preg_replace('/[^a-z0-9]+/i', '_', $value) ?? $value;
    $value = trim($value, '_');
    return $value;
}

function title_case_simple(string $value): string {
    return preg_replace_callback('/\b([a-z])/i', static function (array $m): string {
        return strtoupper($m[1]);
    }, strtolower($value)) ?? $value;
}

function add_candidate_names(array &$names, string $raw): void {
    $raw = trim($raw);
    if ($raw === '') return;

    $stem = preg_replace('/\.(png|webp|jpe?g|svg)$/i', '', $raw) ?? $raw;
    $stem = trim($stem);
    if ($stem === '') return;

    $lower = strtolower($stem);
    $title = title_case_simple($stem);
    $fileKey = icon_file_key($stem);
    $noSpace = preg_replace('/\s+/', '', $stem) ?? $stem;
    $lowerNoSpace = strtolower($noSpace);
    $fileKeyNoUnderscore = str_replace('_', '', $fileKey);

    foreach ([$stem, $lower, $title, $noSpace, $lowerNoSpace, $fileKey, $fileKeyNoUnderscore] as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '' && !in_array($candidate, $names, true)) {
            $names[] = $candidate;
        }
    }
}

function find_icon_file(string $dir, array $names, array $allowedExts): ?string {
    if (!is_dir($dir)) return null;

    // Exact candidate pass first.
    foreach ($names as $name) {
        foreach ($allowedExts as $ext) {
            $file = $dir . '/' . $name . '.' . $ext;
            if (is_file($file) && filesize($file) > 0) return $file;
        }
    }

    // Case-insensitive pass so mixed-case filenames such as Commander_Zilyana.webp work.
    $want = [];
    foreach ($names as $name) {
        foreach ($allowedExts as $ext) {
            $want[strtolower($name . '.' . $ext)] = true;
        }
    }

    $dh = @opendir($dir);
    if (!$dh) return null;
    while (($entry = readdir($dh)) !== false) {
        if ($entry === '.' || $entry === '..') continue;
        if (isset($want[strtolower($entry)])) {
            $file = $dir . '/' . $entry;
            if (is_file($file) && filesize($file) > 0) {
                closedir($dh);
                return $file;
            }
        }
    }
    closedir($dh);

    return null;
}

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
    serve_icon($defaultFile);
}

// Last-resort 404 only if the default asset is missing from the codebase.
http_response_code(404);
