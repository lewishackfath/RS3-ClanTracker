<?php
declare(strict_types=1);

/**
 * Resolve calendar weeks in the clan's named timezone before converting to UTC.
 * Use IANA identifiers such as Australia/Sydney for seasonal offset changes;
 * fixed offsets and abbreviations intentionally retain their fixed offset.
 */
function tracker_timezone(string $name): DateTimeZone {
    try { return new DateTimeZone($name ?: 'UTC'); }
    catch (Throwable $e) { return new DateTimeZone('UTC'); }
}

/** @return array{0:DateTimeImmutable,1:DateTimeImmutable} */
function tracker_cap_week_bounds_utc(
    DateTimeImmutable $at,
    string $timezone,
    int $resetWeekday,
    string $resetTime
): array {
    $tz = tracker_timezone($timezone);
    $utc = new DateTimeZone('UTC');
    $local = $at->setTimezone($tz);
    // Support Sunday as either 0 (database convention) or ISO weekday 7.
    $weekday = (($resetWeekday % 7) + 7) % 7;
    $daysSinceReset = ((int)$local->format('w') - $weekday + 7) % 7;
    $parts = array_map('intval', explode(':', $resetTime));
    $time = sprintf('%02d:%02d:%02d', $parts[0] ?? 0, $parts[1] ?? 0, $parts[2] ?? 0);

    // Calendar dates are advanced independently of the reset time. If PHP
    // normalises a nonexistent local time forward through a DST gap, that
    // adjustment must not carry into the preceding or following week's reset.
    $date = new DateTimeImmutable($local->format('Y-m-d'), $utc);
    $date = $date->modify("-{$daysSinceReset} days");
    $resetOn = static function(DateTimeImmutable $day) use ($tz, $time): DateTimeImmutable {
        // Construct afresh so repeated local times resolve consistently,
        // regardless of which side of a clock change the activity occurred on.
        return new DateTimeImmutable($day->format('Y-m-d') . ' ' . $time, $tz);
    };
    $start = $resetOn($date);
    if ($at < $start) {
        $date = $date->modify('-7 days');
        $start = $resetOn($date);
    }
    $end = $resetOn($date->modify('+7 days'));
    return [$start->setTimezone($utc), $end->setTimezone($utc)];
}

function tracker_week_window(array $clan, ?DateTimeImmutable $now = null): array {
    $tz = tracker_timezone((string)($clan['timezone'] ?? 'UTC'));
    $weekday = (int)($clan['reset_weekday'] ?? 1);
    $time = (string)($clan['reset_time'] ?? '00:00:00');
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    [$start, $end] = tracker_cap_week_bounds_utc($now, $tz->getName(), $weekday, $time);
    [$previousStart] = tracker_cap_week_bounds_utc($start->modify('-1 second'), $tz->getName(), $weekday, $time);

    return [
        'timezone' => $tz->getName(),
        'week_start_local' => $start->setTimezone($tz)->format('Y-m-d H:i:s'),
        'week_end_local' => $end->setTimezone($tz)->format('Y-m-d H:i:s'),
        'week_start_utc' => $start->format('Y-m-d H:i:s'),
        'week_end_utc' => $end->format('Y-m-d H:i:s'),
        'previous_week_start_utc' => $previousStart->format('Y-m-d H:i:s'),
    ];
}

function tracker_period_window(string $period, array $weekWindow): array {
    $period = strtolower(trim($period));
    if ($period === '') $period = '7d';

    // Clan timezone (falls back to UTC)
    $tzName = (string)($weekWindow['timezone'] ?? 'UTC');
    try { $clanTz = new DateTimeZone($tzName); }
    catch (Throwable $e) { $tzName = 'UTC'; $clanTz = new DateTimeZone('UTC'); }

    $nowUtc = new DateTime('now', new DateTimeZone('UTC'));
    $nowLocal = new DateTime('now', $clanTz);

    if ($period === 'thisweek') {
        return [
            'period' => 'thisweek',
            'start_utc' => $weekWindow['week_start_utc'],
            'end_utc' => $weekWindow['week_end_utc'],
        ];
    }

    if ($period === 'lastweek') {
        // Both boundaries were resolved from local reset dates, which may be
        // 167, 168 or 169 hours apart across a daylight-saving transition.
        $start = new DateTime($weekWindow['previous_week_start_utc'], new DateTimeZone('UTC'));
        $end = new DateTime($weekWindow['week_start_utc'], new DateTimeZone('UTC'));
        return [
            'period' => 'lastweek',
            'start_utc' => $start->format('Y-m-d H:i:s'),
            'end_utc' => $end->format('Y-m-d H:i:s'),
        ];
    }

    // Month windows are based on the clan's local timezone
    if ($period === 'thismonth') {
        $startLocal = new DateTime($nowLocal->format('Y-m-01 00:00:00'), $clanTz);
        $startUtc = clone $startLocal; $startUtc->setTimezone(new DateTimeZone('UTC'));
        return [
            'period' => 'thismonth',
            'start_utc' => $startUtc->format('Y-m-d H:i:s'),
            'end_utc' => $nowUtc->format('Y-m-d H:i:s'),
        ];
    }

    if ($period === 'lastmonth') {
        $startThisMonthLocal = new DateTime($nowLocal->format('Y-m-01 00:00:00'), $clanTz);
        $startLastMonthLocal = clone $startThisMonthLocal;
        $startLastMonthLocal->modify('-1 month');

        $startUtc = clone $startLastMonthLocal; $startUtc->setTimezone(new DateTimeZone('UTC'));
        $endUtc = clone $startThisMonthLocal; $endUtc->setTimezone(new DateTimeZone('UTC'));
        // make end inclusive for queries using <= :endUtc
        $endUtc->modify('-1 second');

        return [
            'period' => 'lastmonth',
            'start_utc' => $startUtc->format('Y-m-d H:i:s'),
            'end_utc' => $endUtc->format('Y-m-d H:i:s'),
        ];
    }

    // All-time: let the queries find the earliest snapshot automatically
    if ($period === 'alltime') {
        return [
            'period' => 'alltime',
            'start_utc' => '1970-01-01 00:00:00',
            'end_utc' => $nowUtc->format('Y-m-d H:i:s'),
        ];
    }

    $dur = null;
    if ($period === '24h') $dur = 'PT24H';
    elseif ($period === '7d') $dur = 'P7D';
    elseif ($period === '30d') $dur = 'P30D';
    elseif ($period === '90d') $dur = 'P90D';

    if ($dur) {
        $start = clone $nowUtc;
        $start->sub(new DateInterval($dur));
        return [
            'period' => $period,
            'start_utc' => $start->format('Y-m-d H:i:s'),
            'end_utc' => $nowUtc->format('Y-m-d H:i:s'),
        ];
    }

    $start = clone $nowUtc;
    $start->sub(new DateInterval('P7D'));
    return [
        'period' => '7d',
        'start_utc' => $start->format('Y-m-d H:i:s'),
        'end_utc' => $nowUtc->format('Y-m-d H:i:s'),
    ];
}

