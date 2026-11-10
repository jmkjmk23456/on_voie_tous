<?php
// A frequency model for platforms and sections: where did this train, trains from
// the same planned track, trains of the same line and destination, and all trains
// at the station leave from on previous days? Older days count less (DECAY per
// day). Each level is shrunk towards the next more general one with weight
// SMOOTHING, so a single observation doesn't become a 100 % prediction.
//
// It's the baseline a gradient-boosted model would have to beat, and it works
// from the second day of collecting on.
declare(strict_types=1);

const DECAY = 0.95;
// Chosen on 2–3 Nov: 2 gave the best log loss and the best calibrated probabilities.
const SMOOTHING = 2.0;
const TARGETS = ['platform', 'section'];
// From the most to the least specific. "trainday" is the same train on the same
// kind of day: rj 170 left Praha hl.n. from platform 4 on weekdays, 6 at weekends.
const LEVELS = ['trainday', 'train', 'track', 'line', 'station'];
// Czech public holidays, run like weekends.
const HOLIDAYS = ['2026-11-17', '2026-12-24', '2026-12-25', '2026-12-26', '2027-01-01',
    '2027-03-26', '2027-03-29', '2027-05-01', '2027-05-08', '2027-07-05', '2027-07-06',
    '2027-09-28', '2027-10-28', '2027-11-17', '2027-12-24', '2027-12-25', '2027-12-26'];

/** "W" for working days, "WE" for weekends and public holidays. */
function day_type(string $date): string
{
    return in_array($date, HOLIDAYS, true) || (int)date('N', strtotime($date)) >= 6 ? 'WE' : 'W';
}

/**
 * Splits what an SŽ board shows into platform, section and track:
 * "6J" → 6, J · "6" → 6, whole · "3 / 4 A-B" → 3, A-B, track 4 · "4 / 14J" → 4, J, track 14
 */
function parse_platform(string $raw): ?array
{
    $raw = trim(str_replace(['«', '»'], '', $raw));
    if ($raw === '' || $raw === '–' || $raw === '-') {
        return null;
    }
    $parts = array_map('trim', explode('/', $raw, 2));
    if (!preg_match('/^(\d+)\s*([A-Za-z](?:\s*-\s*[A-Za-z])?)?$/', $parts[0], $left)) {
        return ['platform' => strtoupper($parts[0]), 'section' => 'ganz', 'track' => null];
    }
    $section = $left[2] ?? '';
    $track = null;
    if (isset($parts[1]) && preg_match('/^(\d+[a-z]?)?\s*([A-Z](?:\s*-\s*[A-Z])?)?$/', $parts[1], $right)) {
        $track = ($right[1] ?? '') !== '' ? $right[1] : null;
        if ($section === '') {
            $section = $right[2] ?? '';
        }
    }
    $section = strtoupper(str_replace(' ', '', $section));
    return ['platform' => $left[1], 'section' => $section === '' ? 'ganz' : $section, 'track' => $track];
}

/** "Os 9151" → "9151", so SŽ and Transitous trains can be matched. */
function train_number(string $train): string
{
    return preg_match('/(\d+)\s*$/', $train, $m) ? ltrim($m[1], '0') : '';
}

/** The board's "HH:MM" as a date-time near the moment it was read. */
function board_time(DateTimeImmutable $seen, string $hhmm): ?DateTimeImmutable
{
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
        return null;
    }
    $time = $seen->setTime((int)$m[1], (int)$m[2]);
    $diff = $time->getTimestamp() - $seen->getTimestamp();
    if ($diff > 12 * 3600) {
        $time = $time->modify('-1 day');
    } elseif ($diff < -12 * 3600) {
        $time = $time->modify('+1 day');
    }
    return $time;
}

/**
 * Condenses one day file into one observation per train that got a platform:
 * the platform it finally left from, when that was first shown, and the
 * features known in advance (planned track, line, destination).
 */
function summarize_day(string $file, string $date): array
{
    $zone = new DateTimeZone('Europe/Prague');
    $trains = [];
    $tracks = [];
    $gz = gzopen($file, 'rb');
    while (($line = gzgets($gz, 16 * 1024 * 1024)) !== false) {
        $record = json_decode($line, true);
        if (!is_array($record)) {
            continue;
        }
        $seen = new DateTimeImmutable($record['t']);
        $station = $record['st'];
        if ($record['src'] === 'tr') {
            foreach ($record['rows'] as $row) {
                $number = train_number($row['number'] ?? '');
                $when = $row['schedDep'] ?? $row['schedArr'] ?? null;
                if ($number === '' || $when === null || !str_contains($row['stop'] ?? '', 'CZPTT')
                    || (new DateTimeImmutable($when))->setTimezone($zone)->format('Y-m-d') !== $date) {
                    continue;
                }
                $tracks["$station|$number"] ??= $row['schedTrack'] ?? null;
            }
            continue;
        }
        foreach ($record['rows'] as $row) {
            $number = train_number($row['train'] ?? '');
            $departure = board_time($seen, $row['time'] ?? '');
            if ($number === '' || $departure === null || $departure->format('Y-m-d') !== $date) {
                continue;
            }
            $key = "$station|$number";
            $train = $trains[$key] ?? [
                'st' => $station, 'date' => $date, 'no' => $number, 'train' => $row['train'],
                'line' => $row['line'], 'dest' => $row['dest'], 'time' => $row['time'],
                'shown' => null, 'firstShown' => null, 'firstSeen' => null,
            ];
            $actual = board_time($seen, $row['actual'] ?? '') ?? $departure;
            $train['delay'] = intdiv($actual->getTimestamp() - $departure->getTimestamp(), 60);
            if (parse_platform($row['platform'] ?? '') !== null) {
                $train['shown'] = $row['platform'];
                if ($train['firstSeen'] === null) {
                    $train['firstShown'] = $row['platform'];
                    $train['firstSeen'] = intdiv($actual->getTimestamp() - $seen->getTimestamp(), 60);
                }
            }
            $trains[$key] = $train;
        }
    }
    gzclose($gz);

    $observations = [];
    foreach ($trains as $key => $train) {
        $platform = $train['shown'] === null ? null : parse_platform($train['shown']);
        if ($platform === null) {
            continue;
        }
        $observations[] = [
            'st' => $train['st'], 'date' => $date, 'no' => $train['no'], 'train' => $train['train'],
            'line' => $train['line'], 'dest' => $train['dest'], 'time' => $train['time'],
            'delay' => $train['delay'], 'schedTrack' => $tracks[$key] ?? null,
            'shown' => $train['shown'], 'platform' => $platform['platform'], 'section' => $platform['section'],
            'changed' => $train['firstShown'] !== $train['shown'],
            'lead' => $train['firstSeen'],
        ];
    }
    return $observations;
}

/** Keys for each level, from the most to the least specific. */
function model_keys(string $date, string $station, string $number, ?string $track, string $line, string $dest, string $train): array
{
    $category = strtok($train, ' ') ?: '';
    return [
        'trainday' => "$station|$number|" . day_type($date),
        'train' => "$station|$number",
        'track' => $track === null || $track === '' ? null : "$station|$track",
        'line' => "$station|" . ($line !== '' && $line !== '–' ? $line : $category) . "|$dest",
        'station' => $station,
    ];
}

/** @param array<string, array> $summaries observations per date */
function build_model(array $summaries, string $today): array
{
    $model = [];
    $ref = new DateTimeImmutable($today);
    foreach ($summaries as $date => $observations) {
        $age = (int)(new DateTimeImmutable($date))->diff($ref)->format('%a');
        $weight = DECAY ** $age;
        foreach ($observations as $o) {
            $keys = model_keys($o['date'], $o['st'], $o['no'], $o['schedTrack'], $o['line'], $o['dest'], $o['train']);
            foreach (LEVELS as $level) {
                if ($keys[$level] === null) {
                    continue;
                }
                foreach (TARGETS as $target) {
                    $model[$target][$level][$keys[$level]][$o[$target]] =
                        ($model[$target][$level][$keys[$level]][$o[$target]] ?? 0) + $weight;
                }
            }
        }
    }
    return $model;
}

/**
 * Prediction per target: the most likely value, its probability, the runner-up,
 * the most specific level that contributed, and that level's (weighted) count.
 * "why" lists what each level saw, most specific first, as [level, count, {value: count}].
 * $levels allows evaluating a restricted model.
 */
function predict(array $model, array $keys, array $levels = LEVELS): array
{
    $result = [];
    foreach (TARGETS as $target) {
        $dist = null;
        $used = null;
        $why = [];
        foreach (array_reverse($levels) as $level) {
            $counts = $keys[$level] === null ? null : ($model[$target][$level][$keys[$level]] ?? null);
            if (!$counts) {
                continue;
            }
            $total = array_sum($counts);
            arsort($counts);
            array_unshift($why, [$level, round($total, 1),
                array_map(fn($c) => round($c, 1), array_slice($counts, 0, 4, true))]);
            if ($dist === null) {
                $dist = array_map(fn($c) => $c / $total, $counts);
            } else {
                $mixed = [];
                foreach (array_keys($counts + $dist) as $value) {
                    $mixed[$value] = (($counts[$value] ?? 0) + SMOOTHING * ($dist[$value] ?? 0)) / ($total + SMOOTHING);
                }
                $dist = $mixed;
            }
            $used = [$level, $total];
        }
        if ($dist === null) {
            continue;
        }
        arsort($dist);
        $values = array_keys($dist);
        $result[$target] = [
            'value' => (string)$values[0],
            'p' => round($dist[$values[0]], 3),
            'alt' => isset($values[1]) ? (string)$values[1] : null,
            'level' => $used[0],
            'n' => round($used[1], 1),
            'why' => $why,
        ];
    }
    return $result;
}

/**
 * For each day, predicts every train with a model built only from the days
 * before it, and compares with where it actually left from.
 */
function evaluate(array $summaries, array $stations): array
{
    ksort($summaries);
    $days = [];
    $calibration = [];
    $trains = [];
    $previous = [];
    foreach ($summaries as $date => $observations) {
        $model = build_model($previous, $date);
        $per = [];
        foreach ($observations as $o) {
            $s = &$per[$o['st']];
            $s ??= ['n' => 0, 'covered' => 0, 'platform' => 0, 'section' => 0, 'sectionN' => 0,
                'trackCovered' => 0, 'trackPlatform' => 0, 'changed' => 0, 'leads' => []];
            $s['n']++;
            $s['changed'] += $o['changed'] ? 1 : 0;
            if ($o['lead'] !== null) {
                $s['leads'][] = $o['lead'];
            }
            $keys = model_keys($o['date'], $o['st'], $o['no'], $o['schedTrack'], $o['line'], $o['dest'], $o['train']);
            $guess = predict($model, $keys);
            if (isset($guess['platform'])) {
                $hit = $guess['platform']['value'] === $o['platform'];
                $s['covered']++;
                $s['platform'] += $hit ? 1 : 0;
                $bin = min(9, (int)floor($guess['platform']['p'] * 10));
                $calibration[$date][$bin][0] = ($calibration[$date][$bin][0] ?? 0) + 1;
                $calibration[$date][$bin][1] = ($calibration[$date][$bin][1] ?? 0) + ($hit ? 1 : 0);
            }
            if (isset($guess['section'])) {
                $s['sectionN']++;
                $s['section'] += $guess['section']['value'] === $o['section'] ? 1 : 0;
            }
            $byTrack = predict($model, $keys, ['track']);
            if (isset($byTrack['platform'])) {
                $s['trackCovered']++;
                $s['trackPlatform'] += $byTrack['platform']['value'] === $o['platform'] ? 1 : 0;
            }
            unset($s);
            if (isset($guess['platform'])) {
                $category = strtok($o['train'], ' ') ?: '';
                $trains[] = [
                    $date, $o['st'], $o['time'], $category,
                    $o['line'] !== '' && $o['line'] !== '–' ? $o['line'] : $category, $o['dest'],
                    $guess['platform']['level'], $guess['platform']['p'],
                    $guess['platform']['value'] === $o['platform'] ? 1 : 0,
                    isset($guess['section']) ? ($guess['section']['value'] === $o['section'] ? 1 : 0) : null,
                    isset($byTrack['platform']) ? ($byTrack['platform']['value'] === $o['platform'] ? 1 : 0) : null,
                    $o['changed'] ? 1 : 0, $o['lead'],
                ];
            }
        }
        foreach ($per as $station => $s) {
            sort($s['leads']);
            $days[] = [
                'date' => $date, 'st' => $station, 'n' => $s['n'], 'covered' => $s['covered'],
                'platform' => $s['platform'], 'sectionN' => $s['sectionN'], 'section' => $s['section'],
                'trackCovered' => $s['trackCovered'], 'trackPlatform' => $s['trackPlatform'],
                'changed' => $s['changed'],
                'leadMedian' => $s['leads'] ? $s['leads'][intdiv(count($s['leads']), 2)] : null,
            ];
        }
        $previous[$date] = $observations;
    }
    return [
        'stations' => array_map(fn($s) => $s['name'], $stations),
        'days' => $days,
        'calibration' => $calibration,
        // One row per predicted train, for filtering on the statistics page.
        'trainFields' => ['date', 'st', 'time', 'category', 'line', 'dest', 'level', 'p',
            'platformHit', 'sectionHit', 'trackHit', 'changed', 'lead'],
        'trains' => $trains,
    ];
}
