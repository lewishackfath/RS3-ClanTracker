<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/functions/activity_helpers.php';
require_once __DIR__ . '/../api/functions/rank_up_detection.php';

function same($actual, $expected, string $message): void {
    if ($actual !== $expected) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$utc = new DateTimeZone('UTC');
$cases = [
    // Stored Monday midnight is standard time, even when the displayed time is in DST.
    // Zone, instant within week, expected UTC boundaries, elapsed hours.
    ['Australia/Sydney', '2026-10-02 12:00:00', '2026-09-27 14:00:00', '2026-10-04 14:00:00', 168],
    ['Australia/Sydney', '2026-04-03 12:00:00', '2026-03-29 14:00:00', '2026-04-05 14:00:00', 168],
    ['Europe/London', '2026-03-27 12:00:00', '2026-03-23 00:00:00', '2026-03-30 00:00:00', 168],
    ['Europe/London', '2026-10-23 12:00:00', '2026-10-19 00:00:00', '2026-10-26 00:00:00', 168],
    ['America/New_York', '2026-03-06 12:00:00', '2026-03-02 05:00:00', '2026-03-09 05:00:00', 168],
    ['America/New_York', '2026-10-30 12:00:00', '2026-10-26 05:00:00', '2026-11-02 05:00:00', 168],
    ['Australia/Lord_Howe', '2026-10-02 12:00:00', '2026-09-27 13:30:00', '2026-10-04 13:30:00', 168],
    ['Australia/Lord_Howe', '2026-04-03 12:00:00', '2026-03-29 13:30:00', '2026-04-05 13:30:00', 168],
    ['Australia/Brisbane', '2026-10-02 12:00:00', '2026-09-27 14:00:00', '2026-10-04 14:00:00', 168],
    ['Asia/Kolkata', '2026-10-02 12:00:00', '2026-09-27 18:30:00', '2026-10-04 18:30:00', 168],
    ['UTC', '2026-10-02 12:00:00', '2026-09-28 00:00:00', '2026-10-05 00:00:00', 168],
    ['+10:00', '2026-10-02 12:00:00', '2026-09-27 14:00:00', '2026-10-04 14:00:00', 168],
];

// Exercise the actual rank-up guard without database access or notifications.
class GuardStatement extends PDOStatement {
    public array $params = [];
    public function execute(?array $params = null): bool { $this->params = $params ?? []; return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return ['id' => 1]; // Stop after the first guard query.
    }
}
class GuardDatabase extends PDO {
    public GuardStatement $statement;
    public function __construct() { $this->statement = new GuardStatement(); }
    public function prepare(string $query, array $options = []): PDOStatement|false { return $this->statement; }
}

foreach ($cases as [$zone, $instant, $expectedStart, $expectedEnd, $hours]) {
    $clan = ['timezone' => $zone, 'reset_weekday' => 1, 'reset_time' => '00:00:00'];
    $at = new DateTimeImmutable($instant, $utc);
    [$start, $end] = ah_cap_week_bounds_utc($at, $zone, 1, '00:00:00');
    same($start->format('Y-m-d H:i:s'), $expectedStart, "$zone start");
    same($end->format('Y-m-d H:i:s'), $expectedEnd, "$zone end");
    same((float)(($end->getTimestamp() - $start->getTimestamp()) / 3600), (float)$hours, "$zone duration");

    // The dashboard, activity processors and XP periods must agree at both edges.
    $week = tracker_week_window($clan, $at);
    same($week['week_start_utc'], $expectedStart, "$zone API start");
    same($week['week_end_utc'], $expectedEnd, "$zone API end");
    same(tracker_period_window('thisweek', $week)['end_utc'], $expectedEnd, "$zone thisweek");
    same(tracker_week_window($clan, $end->modify('-1 microsecond'))['week_start_utc'], $expectedStart, "$zone before reset");
    $next = tracker_week_window($clan, $end);
    same($next['week_start_utc'], $expectedEnd, "$zone exact reset");
    $last = tracker_period_window('lastweek', $next);
    same($last['start_utc'], $expectedStart, "$zone lastweek start");
    same($last['end_utc'], $expectedEnd, "$zone lastweek end");
    same(tracker_period_window('lastweek', $week)['end_utc'], $expectedStart, "$zone adjacent XP windows");

    $pdo = new GuardDatabase();
    detect_and_notify_rank_up($pdo, ['id' => 1] + $clan, ['id' => 1, 'rsn' => 'Test'], $expectedStart, [], '');
    same($pdo->statement->params[':ws'], $expectedStart, "$zone rank guard start");
    same($pdo->statement->params[':we'], $expectedEnd, "$zone rank guard end");
}

// Explicit display expectations: the DB value remains 20:00 standard time.
$clan = ['timezone' => 'Australia/Sydney', 'reset_weekday' => 1, 'reset_time' => '20:00:00'];
foreach ([
    ['2026-01-12 00:00:00', '2026-01-12 21:00:00', '2026-01-12 10:00:00'],
    ['2026-07-12 00:00:00', '2026-07-13 20:00:00', '2026-07-13 10:00:00'],
    ['2026-10-02 00:00:00', '2026-10-05 21:00:00', '2026-10-05 10:00:00'],
    ['2026-10-12 00:00:00', '2026-10-12 21:00:00', '2026-10-12 10:00:00'],
] as [$instant, $localEnd, $utcEnd]) {
    $week = tracker_week_window($clan, new DateTimeImmutable($instant, $utc));
    same($week['week_end_local'], $localEnd, 'Sydney displayed reset');
    same($week['week_end_utc'], $utcEnd, 'Sydney countdown instant');
}
$lordHowe = tracker_week_window(array_replace($clan, ['timezone' => 'Australia/Lord_Howe']), new DateTimeImmutable('2026-10-12 00:00:00', $utc));
same($lordHowe['week_end_local'], '2026-10-12 20:30:00', 'Half-hour DST display');
same($lordHowe['week_end_utc'], '2026-10-12 09:30:00', 'Half-hour standard offset');

// A Sunday 23:30 standard reset displays as Monday 00:30 during Sydney DST.
$clan['reset_weekday'] = 0;
$clan['reset_time'] = '23:30:00';
$before = tracker_week_window($clan, new DateTimeImmutable('2026-10-11 13:15:00', $utc));
same($before['week_end_local'], '2026-10-12 00:30:00', 'DST reset crosses midnight');
$after = tracker_week_window($clan, new DateTimeImmutable('2026-10-11 13:30:00', $utc));
same($after['week_start_utc'], $before['week_end_utc'], 'Standard weekday at reset');
same($after['week_end_local'], '2026-10-19 00:30:00', 'Next reset after weekday rollover');
same(tracker_period_window('lastweek', $after)['start_utc'], $before['week_start_utc'], 'Lastweek after weekday rollover');
same(tracker_week_window(array_replace($clan, ['reset_weekday' => 7]), new DateTimeImmutable('2026-10-11 13:30:00', $utc)), $after, 'ISO Sunday alias');

// Stored standard times are unambiguous through both clock changes.
$clan['reset_time'] = '02:30:00';
$before = tracker_week_window($clan, new DateTimeImmutable('2026-10-03 16:15:00', $utc));
same($before['week_start_local'], '2026-09-27 02:30:00', 'Reset before spring change');
same($before['week_end_local'], '2026-10-04 03:30:00', 'Spring reset display');
$during = tracker_week_window($clan, new DateTimeImmutable('2026-10-03 16:30:00', $utc));
same($during['week_start_utc'], $before['week_end_utc'], 'Spring reset continuity');
same($during['week_end_local'], '2026-10-11 03:30:00', 'DST adjustment continues all summer');
$fold = new DateTimeImmutable('2026-04-04 16:30:00', $utc);
$previous = tracker_week_window($clan, $fold->modify('-1 second'));
$next = tracker_week_window($clan, $fold);
same($previous['week_start_local'], '2026-03-29 03:30:00', 'Reset before autumn change');
same($next['week_start_local'], '2026-04-05 02:30:00', 'Autumn reset display');
same($previous['week_end_utc'], $next['week_start_utc'], 'Autumn reset continuity');
foreach (['2026-04-04 15:15:00', '2026-04-04 15:45:00', '2026-04-04 16:15:00', '2026-04-04 16:45:00'] as $instant) {
    $at = new DateTimeImmutable($instant, $utc);
    $week = tracker_week_window($clan, $at);
    same($week['week_start_utc'], $at < $fold ? $previous['week_start_utc'] : $next['week_start_utc'], 'Repeated time classification');
}
same(tracker_week_window(['timezone' => 'Invalid/Timezone'])['timezone'], 'UTC', 'Invalid timezone fallback');

echo "PASS: standard-time resets, DST display, midnight rollover, XP periods and rank guards\n";
