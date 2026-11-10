<?php
// Records the departure boards of Czech stations that announce platforms late:
// the SŽ board (actual platform and section, e.g. "6J" or "3 / 6") and the
// Transitous departures (planned track, delay, and in Prague the PID platform).
//
// Run every 3 minutes, as a scheduled task:
//   https://…/collect.php?token=…   or   php collect.php <token>
//
// Writes one gzip member per run to data/YYYY-MM-DD.jsonl.gz (one JSON line per
// board that changed since the last run) and a status line to data/runs.log.
declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/model.php';

check_token($argv[1] ?? null);
header('Content-Type: text/plain; charset=utf-8');

$dir = data_dir();
$lock = fopen("$dir/collect.lock", 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Another run is still busy.\n");
}

$stations = require __DIR__ . '/stations.php';
$started = microtime(true);
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Prague'));

$urls = [];
foreach ($stations as $slug => $station) {
    $urls["sz/$slug"] = 'https://provoz.spravazeleznic.cz/Tabule/Pages/StationTable.aspx?Key=' . $station['sz'];
    $urls["tr/$slug"] = 'https://api.transitous.org/api/v5/stoptimes?' . http_build_query([
        'stopId' => 'cz-CZPTT_czptt:stop:CZ:' . $station['czptt'],
        'n' => 60,
    ]);
}
$bodies = fetch_all($urls);

$stateFile = "$dir/state.json";
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$lines = [];
$parsed = [];
$ok = ['sz' => 0, 'tr' => 0];
$changed = 0;
foreach ($urls as $key => $url) {
    [$source, $slug] = explode('/', $key, 2);
    $body = $bodies[$key];
    $rows = $body === null ? null : ($source === 'sz' ? parse_sz($body) : parse_transitous($body));
    if ($rows === null) {
        continue;
    }
    $parsed[$key] = $rows;
    $ok[$source]++;
    $hash = md5(json_encode($rows));
    if (($state[$key] ?? null) === $hash) {
        continue;
    }
    $state[$key] = $hash;
    $changed++;
    $lines[] = json_encode([
        't' => $now->format(DATE_ATOM),
        'src' => $source,
        'st' => $slug,
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

if ($lines) {
    $gz = gzopen("$dir/" . $now->format('Y-m-d') . '.jsonl.gz', 'ab6');
    gzwrite($gz, implode('', $lines));
    gzclose($gz);
}
file_put_contents($stateFile, json_encode($state));

// The current boards with predictions from the latest model, for the prediction page.
$model = is_file("$dir/model.json") ? (json_decode((string)file_get_contents("$dir/model.json"), true) ?: []) : [];
$history = is_file("$dir/history.json") ? (json_decode((string)file_get_contents("$dir/history.json"), true) ?: []) : [];
if (!is_dir("$dir/latest")) {
    mkdir("$dir/latest", 0750);
}
foreach ($stations as $slug => $station) {
    $board = $parsed["sz/$slug"] ?? null;
    if ($board === null) {
        continue;
    }
    $tracks = [];
    foreach ($parsed["tr/$slug"] ?? [] as $row) {
        $number = train_number($row['number'] ?? '');
        if ($number !== '' && str_contains($row['stop'] ?? '', 'CZPTT')) {
            $tracks[$number] ??= $row['schedTrack'] ?? null;
        }
    }
    $replaced = replaced_numbers($board);
    foreach ($board as &$row) {
        $number = train_number($row['train']);
        $row['schedTrack'] = $tracks[$number] ?? null;
        $row['shown'] = parse_platform($row['platform']);
        $row['bus'] = is_bus($row['train']);
        // Replaced from here on: no platform to predict.
        $row['replaced'] = !$row['bus'] && isset($replaced[$number]);
        $row['replacement'] = mentions_replacement($row['note'] ?? '');
        // The board only has times of day; a departure long before now is tomorrow's.
        $date = board_time($now, $row['time'])?->format('Y-m-d') ?? $now->format('Y-m-d');
        $row['prediction'] = $number === '' || $row['bus'] || $row['replaced'] ? [] : predict($model,
            model_keys($date, $slug, $number, $row['schedTrack'], $row['line'], $row['dest'], $row['train']));
        $row['history'] = $row['bus'] ? [] : $history["$slug|$number"] ?? [];
    }
    unset($row);
    file_put_contents("$dir/latest/$slug.json", json_encode(
        ['t' => $now->format(DATE_ATOM), 'name' => $station['name'], 'rows' => $board],
        JSON_UNESCAPED_UNICODE));
}

$status = sprintf("%s sz %d/%d tr %d/%d changed %d %.1fs\n", $now->format(DATE_ATOM),
    $ok['sz'], count($stations), $ok['tr'], count($stations), $changed, microtime(true) - $started);
file_put_contents("$dir/runs.log", $status, FILE_APPEND);
echo $status;
