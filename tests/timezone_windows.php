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
    // Zone, instant within week, expected Monday reset boundaries, elapsed hours.
    ['Australia/Sydney', '2026-10-02 12:00:00', '2026-09-27 14:00:00', '2026-10-04 13:00:00', 167],
    ['Australia/Sydney', '2026-04-03 12:00:00', '2026-03-29 13:00:00', '2026-04-05 14:00:00', 169],
    ['Europe/London', '2026-03-27 12:00:00', '2026-03-23 00:00:00', '2026-03-29 23:00:00', 167],
    ['Europe/London', '2026-10-23 12:00:00', '2026-10-18 23:00:00', '2026-10-26 00:00:00', 169],
    ['America/New_York', '2026-03-06 12:00:00', '2026-03-02 05:00:00', '2026-03-09 04:00:00', 167],
    ['America/New_York', '2026-10-30 12:00:00', '2026-10-26 04:00:00', '2026-11-02 05:00:00', 169],
    ['Australia/Lord_Howe', '2026-10-02 12:00:00', '2026-09-27 13:30:00', '2026-10-04 13:00:00', 167.5],
    ['Australia/Lord_Howe', '2026-04-03 12:00:00', '2026-03-29 13:00:00', '2026-04-05 13:30:00', 168.5],
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

// A reset inside the skipped hour moves forward for that date only.
$clan = ['timezone' => 'Australia/Sydney', 'reset_weekday' => 0, 'reset_time' => '02:30:00'];
$before = tracker_week_window($clan, new DateTimeImmutable('2026-10-03 16:15:00', $utc));
same($before['week_start_local'], '2026-09-27 02:30:00', 'Gap must not shift previous reset');
same($before['week_end_local'], '2026-10-04 03:30:00', 'Gap reset normalises forward');
$during = tracker_week_window($clan, new DateTimeImmutable('2026-10-03 16:30:00', $utc));
same($during['week_start_utc'], $before['week_end_utc'], 'Gap has no overlap');
same($during['week_end_local'], '2026-10-11 02:30:00', 'Gap must not shift next reset');
$after = tracker_week_window($clan, new DateTimeImmutable('2026-10-10 16:00:00', $utc));
same(tracker_period_window('lastweek', $after)['start_utc'], $during['week_start_utc'], 'Lastweek retains normalised reset');

// Repeated wall times resolve to one consistent reset, from either side of DST.
$fold = new DateTimeImmutable('2026-04-05 02:30:00', new DateTimeZone('Australia/Sydney'));
$previous = tracker_week_window($clan, $fold->setTimezone($utc)->modify('-1 second'));
$next = tracker_week_window($clan, $fold);
same($previous['week_end_utc'], $next['week_start_utc'], 'Repeated time has one boundary');
foreach (['2026-04-04 15:15:00', '2026-04-04 15:45:00', '2026-04-04 16:15:00', '2026-04-04 16:45:00'] as $instant) {
    $at = new DateTimeImmutable($instant, $utc);
    $week = tracker_week_window($clan, $at);
    same($week['week_start_utc'], $at < $fold ? $previous['week_start_utc'] : $next['week_start_utc'], 'Repeated time classification');
}
same(tracker_week_window(array_replace($clan, ['reset_weekday' => 7]), $fold), $next, 'ISO Sunday alias');
same(tracker_week_window(['timezone' => 'Invalid/Timezone'])['timezone'], 'UTC', 'Invalid timezone fallback');

echo "PASS: DST transitions, half-hour DST, fixed zones, reset edges, XP periods and rank guards\n";
