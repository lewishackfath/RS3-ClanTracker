<?php
declare(strict_types=1);

require_once __DIR__ . '/icon_files.php';
require_once __DIR__ . '/wiki_icons.php';

function ai_normalise(string $value): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace(['’', '‘', '`'], "'", $value)) ?? $value), 'UTF-8');
}

function ai_clean(string $value): string
{
    if (class_exists('Normalizer')) $value = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
    $value = str_replace(['’', '‘', '`', "\u{00a0}"], ["'", "'", "'", ' '], $value);
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    return trim(preg_replace('/\.$/u', '', trim($value, "\"'“”")) ?? $value);
}

/** Read the same commented/trailing-comma JSON maps accepted by the tracker. */
function ai_json(string $root, string $path): array
{
    $file = $root . '/' . $path;
    if (!is_file($file)) return [];
    $text = ltrim((string)file_get_contents($file), "\xEF\xBB\xBF");
    $text = preg_replace_callback('~"(?:[^"\\\\]|\\\\.)*"|//[^\r\n]*|/\*.*?\*/~s',
        static fn(array $m): string => $m[0][0] === '"' ? $m[0] : '', $text) ?? $text;
    $text = preg_replace_callback('~"(?:[^"\\\\]|\\\\.)*"|,\s*(?=[}\]])~s',
        static fn(array $m): string => $m[0][0] === '"' ? $m[0] : '', $text) ?? $text;
    $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    return is_array($data) ? $data : [];
}

function ai_regex(string $pattern, string $flags = 'i'): string
{
    // JSON patterns use JavaScript syntax; named captures also work in PCRE.
    return '~' . str_replace('~', '\\~', $pattern) . '~u' . (str_contains($flags, 'i') ? 'i' : '')
        . (str_contains($flags, 'm') ? 'm' : '') . (str_contains($flags, 's') ? 's' : '');
}

function ai_clean_item(string $item, array $rules): string
{
    $item = ai_clean($item);
    foreach ($rules['replacements'] ?? [] as $rule) {
        if (!is_array($rule) || empty($rule['from'])) continue;
        $item = @preg_replace(ai_regex($rule['from'], $rule['flags'] ?? 'i'), (string)($rule['to'] ?? ''), $item,
            str_contains($rule['flags'] ?? 'i', 'g') ? -1 : 1) ?? $item;
    }
    foreach (array_unique(array_merge(['pair of', 'some'], $rules['strip_prefixes'] ?? [])) as $prefix) {
        $item = preg_replace('~^' . preg_quote($prefix, '~') . '\s+~iu', '', $item) ?? $item;
    }
    foreach ($rules['strip_suffixes'] ?? [] as $suffix) {
        $item = preg_replace('~\s+' . preg_quote($suffix, '~') . '$~iu', '', $item) ?? $item;
    }
    return ai_clean($item);
}

function ai_boss_variants(string $boss): array
{
    $boss = preg_replace('/\(\s*hard\s*mode\s*\)/iu', ' ', ai_clean($boss)) ?? $boss;
    $boss = trim(preg_replace('/\s+/u', ' ', $boss) ?? $boss, " \t\n\r\0\x0B-–—:;,.");
    $out = [];
    foreach ([$boss, preg_replace('/^the\s+/iu', '', $boss), explode(',', $boss)[0]] as $name) {
        $name = trim((string)$name);
        if ($name === '') continue;
        $out[] = $name;
        if (!str_contains($name, ',')) {
            if (preg_match('/ies$/i', $name)) $out[] = substr($name, 0, -3) . 'y';
            if (preg_match('/s$/i', $name) && !preg_match('/(ss|us|x)$/i', $name)) $out[] = substr($name, 0, -1);
        }
    }
    return array_values(array_unique($out));
}

/** Build one authoritative plan for both feed images and background scans. */
function ai_plan(string $root, array $activity): array
{
    $text = trim((string)($activity['activity_text'] ?? ''));
    $details = trim((string)($activity['activity_details'] ?? ''));
    $combined = $text . ' ' . $details;
    $kind = 'activity';
    $name = $text ?: $details;
    // Unclassified activities retain their details/numbers: guessing a common
    // pattern could hide an unresolved variant when only one example is fixed.
    // Once a mapping matches, its stable rule key groups the activity pattern.
    $identity = ai_normalise($text) . "\n" . ai_normalise($details);
    $candidates = [];
    $phrases = ai_json($root, 'assets/activity/monsters/activity_phrases.json');
    foreach ($phrases['patterns'] ?? $phrases as $rule) {
        if (!is_array($rule)) continue;
        $regex = $rule['activity_regex'] ?? $rule['regex'] ?? '';
        if ($regex === '' || @preg_match(ai_regex($regex, $rule['regex_flags'] ?? $rule['flags'] ?? 'i'), $text, $m) !== 1) continue;
        $boss = $m[$rule['boss_group'] ?? $rule['bossGroup'] ?? 'boss'] ?? '';
        if ($boss === '') continue;
        $variants = ai_boss_variants($boss);
        if (!$variants) continue;
        $kind = 'boss';
        $name = $variants[0];
        $identity = ai_normalise($name);
        $wanted = array_map(static fn(string $v): string => str_replace("'", '', ai_normalise($v)), $variants);
        $map = ai_json($root, 'assets/activity/monsters/icon_map.json');
        foreach ($map as $key => $entry) {
            if (!in_array(str_replace("'", '', ai_normalise((string)$key)), $wanted, true)) continue;
            $path = is_string($entry) ? $entry : ($entry['icon'] ?? '');
            if ($path !== '') {
                $identity = ai_normalise((string)$key);
                $path = preg_match('~^(?:/?assets/|https?://)~i', $path) ? $path : 'assets/activity/monsters/' . $path;
                $candidates[] = ['path' => $path];
                $candidates[] = ['type' => 'monster', 'name' => pathinfo($path, PATHINFO_FILENAME)];
            }
            break;
        }
        foreach ($variants as $variant) $candidates[] = ['type' => 'monster', 'name' => $variant];
        break;
    }
    if ($kind !== 'boss') {
        if (preg_match('/has completed|completed:|quest/i', $combined)) {
            $name = 'Quest';
            $identity = 'quest';
            $candidates[] = ['path' => 'assets/activity/quest.png'];
        } elseif (preg_match('/^I found\s+(?:(?:an?|some)\s+)?(.+?)(?:\.\s*)?$/iu', $text, $drop)
            || preg_match('/\bdropped\s+(?:(?:an?|some)\s+)?(.+?)(?:\.\s*|$)/iu', $details, $drop)) {
            $kind = 'item';
            // Keep identity independent of editable cleanup and alias rules.
            $identity = ai_normalise(ai_clean_item($drop[1], []));
            $name = ai_clean_item($drop[1], ai_json($root, 'assets/activity/item_name_cleanup.json'));
            $aliases = [
                'Bandos shield' => 'Bandos warshield', 'Hiss of Saradomin' => "Saradomin's hiss",
                'Bill (pet)' => 'Bill (pet) pet', 'Vitalis' => 'Vitalis (pet)',
                'Zammo the Rak' => 'Zammo the Rak pet', 'Clawdias shell clippings' => "Clawdia's shell clippings",
            ];
            $raw = ai_json($root, 'assets/activity/item_icon_alias.json');
            $raw = $raw['aliases'] ?? $raw;
            foreach ($raw as $from => $to) {
                if (is_array($to)) {
                    $from = $to['from'] ?? $to['source'] ?? $to['runemetrics'] ?? $to['activity_name'] ?? '';
                    $to = $to['to'] ?? $to['target'] ?? $to['wiki'] ?? $to['item_name'] ?? '';
                }
                if (is_string($from) && is_string($to) && $from !== '' && $to !== '') $aliases[$from] = $to;
            }
            $lookup = $name;
            foreach ($aliases as $from => $to) {
                if (str_replace("'", '', ai_normalise($from)) === str_replace("'", '', ai_normalise($name))) {
                    $lookup = ai_clean($to);
                    break;
                }
            }
            // Uploaded local fixes take effect immediately, even during a Wiki outage.
            foreach (array_unique([$lookup, $name]) as $candidate) $candidates[] = ['type' => 'item', 'name' => $candidate];
            foreach (array_unique([$lookup, $name]) as $candidate) $candidates[] = ['wiki' => $candidate];
        } else {
            $skills = 'Attack|Defence|Strength|Constitution|Ranged|Prayer|Magic|Cooking|Woodcutting|Fletching|Fishing|Firemaking|Crafting|Smithing|Mining|Herblore|Agility|Thieving|Slayer|Farming|Runecrafting|Hunter|Construction|Summoning|Dungeoneering|Divination|Invention|Archaeology|Necromancy';
            if (preg_match('/\b(' . $skills . ')\b/i', $details, $skill) || preg_match('/\b(' . $skills . ')\b/i', $text, $skill)) {
                $kind = 'skill';
                $name = ucfirst(strtolower($skill[1]));
                $identity = ai_normalise($name);
                $candidates[] = ['type' => 'skill', 'name' => $name];
            }
            if (preg_match('/levelled|leveled|level up|i am now level/i', $combined)) {
                if ($kind === 'activity') { $name = 'Level up'; $identity = 'level up'; }
                $candidates[] = ['path' => 'assets/activity/level.png'];
            }
        }
    }
    $map = ai_json($root, 'assets/activity/icon_map.json');
    if (!$map) $map = ai_json($root, 'assets/activity_icons/icon_map.json');
    foreach ($map as $mapKey => $entry) {
        if (!is_array($entry) || empty($entry['icon'])) continue;
        $matches = !empty($entry['activity_text']) && str_contains(ai_normalise($combined), ai_normalise($entry['activity_text']));
        if (!$matches && !empty($entry['activity_regex'])) {
            $regex = ai_regex($entry['activity_regex'], $entry['regex_flags'] ?? 'i');
            foreach ([$text, $details, trim($combined)] as $value) {
                if (@preg_match($regex, $value) === 1) { $matches = true; break; }
            }
        }
        if ($matches) {
            // Explicit activity overrides take precedence, just as in the UI.
            $candidates = [['path' => $entry['icon']]];
            $identity = 'map:' . (string)$mapKey;
            break;
        }
    }
    return ['kind' => $kind, 'name' => mb_substr($name ?: 'Unknown activity', 0, 255),
        'key' => hash('sha256', $kind . ':' . $identity), 'candidates' => $candidates];
}

function ai_valid_file(string $root, ?string $file): bool
{
    if ($file === null || !is_file($file) || filesize($file) === 0) return false;
    foreach (['assets/activity/default.png', 'assets/skills/_default.png'] as $placeholder) {
        $default = $root . '/' . $placeholder;
        if (is_file($default) && hash_file('sha256', $file) === hash_file('sha256', $default)) return false;
    }
    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'svg') {
        $doc = new DOMDocument();
        return @$doc->loadXML((string)file_get_contents($file), LIBXML_NONET)
            && $doc->documentElement?->localName === 'svg';
    }
    return @getimagesize($file) !== false;
}

function ai_local_file(string $root, array $candidate): ?string
{
    $dirs = ['skill' => 'assets/skills', 'monster' => 'assets/activity/monsters', 'item' => 'assets/items'];
    if (isset($candidate['path'])) {
        $path = ltrim(str_replace('\\', '/', $candidate['path']), '/');
        $file = realpath($root . '/' . $path);
        $assets = realpath($root . '/assets');
        return $file && $assets && str_starts_with($file, $assets . DIRECTORY_SEPARATOR) && ai_valid_file($root, $file) ? $file : null;
    }
    if (!isset($dirs[$candidate['type'] ?? ''])) return null;
    $names = [];
    // Names cannot escape the known asset directory.
    add_candidate_names($names, basename(str_replace('\\', '/', $candidate['name'])));
    $file = find_icon_file($root . '/' . $dirs[$candidate['type']], $names, ['png', 'webp', 'jpg', 'jpeg', 'svg']);
    return ai_valid_file($root, $file) ? $file : null;
}

function ai_resolve(string $root, array $activity, bool $force = false, ?callable $wiki = null): array
{
    $plan = ai_plan($root, $activity);
    $reason = $plan['candidates'] ? 'missing_asset' : 'missing_mapping';
    $status = 'missing';
    $attempts = [];
    foreach ($plan['candidates'] as $candidate) {
        $attempts[] = $candidate;
        $remoteMap = isset($candidate['path']) && preg_match('~^https?://~i', $candidate['path']);
        if (isset($candidate['wiki']) || $remoteMap) {
            $result = $remoteMap ? wi_mapped_icon($root, $candidate['path'], $force)
                : ($wiki ? $wiki($candidate['wiki'], $force) : wi_resolve($root, $candidate['wiki'], $force));
            if ($result['status'] === 'resolved' && ai_valid_file($root, $result['file'] ?? null)) {
                return $plan + ['status' => 'resolved', 'file' => $result['file'], 'reason' => '', 'attempts' => $attempts];
            }
            if ($result['status'] === 'temporary') { $status = 'temporary'; $reason = $result['reason']; }
        } else {
            $file = ai_local_file($root, $candidate);
            if ($file !== null) return $plan + ['status' => 'resolved', 'file' => $file, 'reason' => '', 'attempts' => $attempts];
        }
    }
    return $plan + ['status' => $status, 'file' => null, 'reason' => $reason, 'attempts' => $attempts];
}
