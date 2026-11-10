<?php
// Condenses the collected days, rebuilds the model and re-evaluates it.
// Run hourly, as a scheduled task:
//   https://…/train.php?token=…   or   php train.php <token>
//
// data/summary/YYYY-MM-DD.json  one observation per train and day
// data/model.json               model from all days, used by collect.php for live predictions
// data/history.json             each train's platforms on the last 14 days
// data/stats.json               day-by-day evaluation, shown on the statistics page
// data/eval.json                the same evaluation per train, for its filters
declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/model.php';

check_token($argv[1] ?? null);
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(600);
ini_set('memory_limit', '512M');

$dir = data_dir();
$lock = fopen("$dir/train.lock", 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Another run is still busy.\n");
}
$started = microtime(true);
$stations = require __DIR__ . '/stations.php';
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Prague')))->format('Y-m-d');

if (!is_dir("$dir/summary")) {
    mkdir("$dir/summary", 0750);
}
$summaries = [];
foreach (glob("$dir/*.jsonl.gz") ?: [] as $file) {
    $date = basename($file, '.jsonl.gz');
    $summaryFile = "$dir/summary/$date.json";
    if (!is_file($summaryFile) || filemtime($summaryFile) < filemtime($file)) {
        file_put_contents($summaryFile, json_encode(summarize_day($file, $date), JSON_UNESCAPED_UNICODE));
        echo "summarized $date\n";
    }
    $summaries[$date] = json_decode((string)file_get_contents($summaryFile), true) ?: [];
}
ksort($summaries);

// Where each train left from on the last 14 days, for the details on the prediction page.
$history = [];
foreach (array_slice($summaries, -14, null, true) as $date => $observations) {
    foreach ($observations as $o) {
        $history["{$o['st']}|{$o['no']}"][] = [$date, $o['shown']];
    }
}
file_put_contents("$dir/history.json.tmp", json_encode($history, JSON_UNESCAPED_UNICODE));
rename("$dir/history.json.tmp", "$dir/history.json");

$model = build_model($summaries, $today);
file_put_contents("$dir/model.json.tmp", json_encode($model, JSON_UNESCAPED_UNICODE));
rename("$dir/model.json.tmp", "$dir/model.json");

$stats = evaluate($summaries, $stations);
$stats['generated'] = (new DateTimeImmutable('now', new DateTimeZone('Europe/Prague')))->format(DATE_ATOM);
$evaluated = ['fields' => $stats['trainFields'], 'rows' => $stats['trains']];
unset($stats['trainFields'], $stats['trains']);
file_put_contents("$dir/eval.json.tmp", json_encode($evaluated, JSON_UNESCAPED_UNICODE));
rename("$dir/eval.json.tmp", "$dir/eval.json");
file_put_contents("$dir/stats.json.tmp", json_encode($stats, JSON_UNESCAPED_UNICODE));
rename("$dir/stats.json.tmp", "$dir/stats.json");

printf("%d days, %d trains, %.1fs\n", count($summaries),
    array_sum(array_map('count', $summaries)), microtime(true) - $started);
