<?php
// Downloads the collected data:
//   export.php?token=…              lists the files and the last runs
//   export.php?token=…&day=2026-11-03  downloads that day's .jsonl.gz
declare(strict_types=1);

require __DIR__ . '/lib.php';

check_token(null);
$dir = data_dir();
$day = (string)($_GET['day'] ?? '');
$json = (string)($_GET['json'] ?? '');

// export.php?token=…&json=stats | model | latest/praha-hl-n | summary/2026-11-03
if ($json !== '') {
    $file = "$dir/$json.json";
    if (!preg_match('#^(stats|model|latest/[a-z-]+|summary/\d{4}-\d{2}-\d{2})$#', $json) || !is_file($file)) {
        http_response_code(404);
        exit("No such file.\n");
    }
    header('Content-Type: application/json; charset=utf-8');
    readfile($file);
    exit;
}

if ($day !== '') {
    $file = "$dir/$day.jsonl.gz";
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !is_file($file)) {
        http_response_code(404);
        exit("No data for that day.\n");
    }
    header('Content-Type: application/gzip');
    header('Content-Length: ' . filesize($file));
    header("Content-Disposition: attachment; filename=\"cz-boards-$day.jsonl.gz\"");
    readfile($file);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
foreach (glob("$dir/*.jsonl.gz") ?: [] as $file) {
    printf("%s  %6.1f MB\n", basename($file, '.jsonl.gz'), filesize($file) / 1e6);
}
$log = is_file("$dir/runs.log") ? file("$dir/runs.log") : [];
echo "\nLast runs:\n", implode('', array_slice($log, -20));
