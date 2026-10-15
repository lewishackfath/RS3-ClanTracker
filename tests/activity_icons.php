<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/functions/activity_icons.php';

function icon_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function icon_fixture_image(string $file, int $colour): void
{
    $img = imagecreatetruecolor(3, 3);
    imagefill($img, 0, 0, $colour);
    imagepng($img, $file);
    clearstatcache();
}
function icon_fixture_remove(string $root): void
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($root);
}

$root = sys_get_temp_dir() . '/activity-icons-' . bin2hex(random_bytes(6));
foreach (['assets/activity/monsters', 'assets/items', 'assets/skills', 'cache/item-icons'] as $dir) mkdir($root . '/' . $dir, 0755, true);
try {
    foreach (['icon_map.json', 'item_name_cleanup.json', 'item_icon_alias.json', 'monsters/icon_map.json', 'monsters/activity_phrases.json'] as $file) {
        copy(__DIR__ . '/../assets/activity/' . $file, $root . '/assets/activity/' . $file);
    }
    icon_fixture_image($root . '/assets/activity/default.png', 0x333333);
    icon_fixture_image($root . '/assets/skills/_default.png', 0x222222);
    icon_fixture_image($root . '/assets/items/Bandos_warshield.webp', 0x123456);
    $noWiki = static function (): array { throw new RuntimeException('Local icons should avoid the Wiki entirely'); };
    $a = ['activity_text' => 'I found a pair of Bandos shield.'];
    $r = ai_resolve($root, $a, false, $noWiki);
    icon_check($r['status'] === 'resolved' && str_ends_with(strtolower($r['file']), 'bandos_warshield.webp'), 'Alias + cleanup + mixed-case local filename');
    icon_check(ai_plan($root, $a)['key'] === ai_plan($root, ['activity_text' => 'I found a Bandos shield.'])['key'], 'Default cleanup deduplicates pair-of wording');
    icon_check(icon_mime_type($r['file']) === 'image/png', 'Content MIME follows actual image bytes');

    icon_fixture_image($root . '/assets/activity/monsters/general_graardor.png', 0x234567);
    foreach (['I killed 3 General Graardors.', 'I killed (Hard mode) General Graardor.', 'I killed General Graardor, a huge war chief. (Hard mode)'] as $text) {
        icon_check(ai_resolve($root, ['activity_text' => $text])['status'] === 'resolved', 'Boss phrase, plural or hard-mode lookup: ' . $text);
    }
    icon_fixture_image($root . '/assets/activity/monsters/unknown_beast.png', 0xabcdef);
    icon_check(ai_resolve($root, ['activity_text' => 'I killed 2 Unknown Beasts.'])['status'] === 'resolved', 'Missing first boss candidate does not hide later singular match');

    icon_fixture_image($root . '/assets/skills/Archaeology.png', 0x112233);
    icon_check(ai_resolve($root, ['activity_text' => '54,000,000 XP in Archaeology'])['status'] === 'resolved', 'Skill activity icon');
    icon_fixture_image($root . '/assets/activity/quest.png', 0x112234);
    icon_check(ai_resolve($root, ['activity_text' => 'Quest completed: Test quest'])['status'] === 'resolved', 'Quest icon');

    $fealty = ['activity_text' => 'Maintained Clan Fealty 3'];
    icon_check(ai_resolve($root, $fealty)['status'] === 'missing', 'Missing explicit mapping asset is tracked');
    copy($root . '/assets/activity/default.png', $root . '/assets/activity/fealty_3.png');
    icon_check(ai_resolve($root, $fealty)['status'] === 'missing', 'Renamed placeholder is never a real icon');
    file_put_contents($root . '/assets/activity/fealty_3.png', '<html>upstream error</html>');
    clearstatcache();
    icon_check(ai_resolve($root, $fealty)['status'] === 'missing', 'Non-image content is rejected');
    icon_fixture_image($root . '/assets/activity/fealty_3.png', 0xabcdef);
    icon_check(ai_resolve($root, $fealty)['status'] === 'resolved', 'Adding a valid mapped image resolves fallback');
    icon_check(ai_plan($root, $fealty)['key'] !== ai_plan($root, ['activity_text' => 'Maintained Clan Fealty 2'])['key'], 'Different explicit mappings must not merge based on numbers');

    $unmapped = ai_resolve($root, ['activity_text' => 'Explored 123 chambers.']);
    icon_check($unmapped['reason'] === 'missing_mapping', 'Unknown activities retain a mapping reason');
    icon_check($unmapped['key'] === ai_plan($root, ['activity_text' => '  EXPLORED 123 chambers.  '])['key'], 'Repeated unclassified activities deduplicate');
    icon_check($unmapped['key'] !== ai_plan($root, ['activity_text' => 'Explored 999 chambers.'])['key'], 'Unclassified numeric variants cannot falsely resolve one another');
    icon_check(ai_local_file($root, ['path' => '../../etc/passwd']) === null, 'Mapped paths cannot escape assets');
    icon_check(ai_local_file($root, ['path' => 'assets/skills/_default.png']) === null, 'Skill default is a placeholder');

    $calls = 0;
    $notFound = static function () use (&$calls): array { $calls++; return ['status' => 'missing', 'reason' => 'wiki_not_found']; };
    $r = wi_resolve($root, 'Missing item', false, $notFound);
    icon_check($r['status'] === 'missing' && $calls === 2, 'Both Wiki locations confirm missing');
    wi_resolve($root, 'Missing item', false, $notFound);
    icon_check($calls === 2, 'Negative cache prevents repeated network requests');
    $bytes = file_get_contents($root . '/assets/activity/fealty_3.png');
    $success = static fn(): array => ['status' => 'resolved', 'bytes' => $bytes];
    $r = wi_resolve($root, 'Missing item', true, $success);
    icon_check($r['status'] === 'resolved' && is_file($r['file']), 'Manual recheck bypasses negative cache');
    $temporary = static fn(): array => ['status' => 'temporary', 'reason' => 'wiki_http_429'];
    icon_check(wi_resolve($root, 'Missing item', true, $temporary)['status'] === 'resolved', 'Valid stale cache survives Wiki outage');
    icon_check(wi_resolve($root, 'Rate limited item', false, $temporary)['status'] === 'temporary', '429 is retryable, not confirmed missing');
    icon_check(wi_mapped_icon($root, 'https://example.test/icon.png', false, $success)['status'] === 'resolved', 'Configured remote images share validated caching');
    icon_check(wi_mapped_icon($root, 'file:///etc/passwd', false, $success)['status'] === 'missing', 'Mapped remote URLs reject non-HTTP schemes');
    $r = ai_resolve($root, ['activity_text' => 'I found a Missing new item.'], false, $temporary);
    icon_check($r['status'] === 'temporary', 'Final fallback retains transient network cause');
    $r = ai_resolve($root, ['activity_text' => 'I found a Missing new item.'], false,
        static fn(): array => ['status' => 'resolved', 'file' => $root . '/assets/activity/default.png']);
    icon_check($r['status'] === 'missing', 'Cached Wiki placeholder does not resolve issue');

    file_put_contents($root . '/assets/activity/icon_map.json', "{\n// comment\n\"Custom\": {\"icon\": \"assets/activity/fealty_3.png\", \"activity_text\": \"http://test\",},\n}");
    icon_check(ai_resolve($root, ['activity_text' => 'http://test'])['status'] === 'resolved', 'Commented JSON preserves URL strings and trailing commas');
    file_put_contents($root . '/assets/activity/icon_map.json', '{invalid json');
    try {
        ai_resolve($root, $fealty);
        throw new RuntimeException('Invalid configuration must fail rather than incorrectly resolving/logging every activity');
    } catch (JsonException $e) {}
    echo "Activity icon resolution, aliases, mappings, placeholders and Wiki cache checks passed.\n";
} finally {
    icon_fixture_remove($root);
}
