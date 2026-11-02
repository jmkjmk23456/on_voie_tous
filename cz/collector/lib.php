<?php
declare(strict_types=1);

const USER_AGENT = 'on-voie-tous-cz-collector/1 (+https://github.com/jmkjmk23456/on_voie_tous)';

/** Stops unless the request (or the CLI argument) carries the token from config.php. */
function check_token(?string $cliToken): void
{
    $config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
    $expected = (string)($config['token'] ?? '');
    $given = PHP_SAPI === 'cli' ? (string)$cliToken : (string)($_GET['token'] ?? '');
    if (strlen($expected) < 16 || !hash_equals($expected, $given)) {
        http_response_code(403);
        exit("Wrong token.\n");
    }
}

function data_dir(): string
{
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    if (!is_file("$dir/.htaccess")) {
        file_put_contents("$dir/.htaccess", "Require all denied\n");
    }
    return $dir;
}

/**
 * Fetches all URLs in parallel.
 * @param array<string, string> $urls
 * @return array<string, ?string> body per key, null on failure
 */
function fetch_all(array $urls): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($urls as $key => $url) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => USER_AGENT,
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[$key] = $handle;
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    $bodies = [];
    foreach ($handles as $key => $handle) {
        $code = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $body = curl_multi_getcontent($handle);
        $bodies[$key] = $code === 200 && is_string($body) && $body !== '' ? $body : null;
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);
    return $bodies;
}

/**
 * Reads the departures from an SŽ board (provoz.spravazeleznic.cz/Tabule).
 * The board only has times of day; the record's timestamp gives the date.
 * Notice rows (e.g. rail replacement buses) are attached to the train above them.
 */
function parse_sz(string $html): ?array
{
    if (!str_contains($html, 'BGVTable')) {
        return null;
    }
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    $rows = [];
    foreach ($xpath->query('//tr[contains(@id, "BGVTable_DXDataRow")]') as $tr) {
        $cells = [];
        foreach ($xpath->query('./td', $tr) as $td) {
            if (preg_match('/tccell\d+_(\d+)$/', $td->getAttribute('id'), $m)) {
                $cells[(int)$m[1]] = $td;
            }
        }
        $text = fn(int $i, int $div = -1): string => clean_text(isset($cells[$i])
            ? ($div < 0 ? $cells[$i]->textContent : ($xpath->query('./div', $cells[$i])->item($div)?->textContent ?? ''))
            : '');

        if (str_contains($tr->getAttribute('class'), 'tableTextRow')) {
            if ($rows) {
                $rows[count($rows) - 1]['note'] = $text(0);
            }
            continue;
        }
        preg_match('/\d{1,2}:\d{2}/', $text(0), $time);
        $row = [
            'time' => $time[0] ?? '',
            'actual' => $text(1),
            'train' => $text(3, 0),
            'operator' => $text(3, 1),
            'line' => $text(4),
            'dest' => $text(6),
            'via' => $text(8),
            'platform' => $text(10),
        ];
        // Some trains are printed in red; what that means isn't documented, so just keep it.
        if (isset($cells[0]) && stripos($doc->saveHTML($cells[0]), '#E60000') !== false) {
            $row['red'] = true;
        }
        $rows[] = $row;
    }
    return $rows;
}

/** Keeps the fields of Transitous' /api/v5/stoptimes that matter for the model. */
function parse_transitous(string $json): ?array
{
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['stopTimes'])) {
        return null;
    }
    $rows = [];
    foreach ($data['stopTimes'] as $st) {
        $place = $st['place'] ?? [];
        $rows[] = array_filter([
            'trip' => $st['tripId'] ?? null,
            'train' => $st['displayName'] ?? null,
            'number' => $st['tripShortName'] ?? null,
            'route' => $st['routeShortName'] ?? null,
            'agency' => $st['agencyName'] ?? null,
            'mode' => $st['mode'] ?? null,
            'headsign' => $st['headsign'] ?? null,
            'stop' => $place['stopId'] ?? null,
            'schedArr' => $place['scheduledArrival'] ?? null,
            'arr' => $place['arrival'] ?? null,
            'schedDep' => $place['scheduledDeparture'] ?? null,
            'dep' => $place['departure'] ?? null,
            'schedTrack' => $place['scheduledTrack'] ?? null,
            'track' => $place['track'] ?? null,
            'rt' => $st['realTime'] ?? null,
            'cancelled' => ($place['cancelled'] ?? false) || ($st['tripCancelled'] ?? false) ?: null,
        ], fn($v) => $v !== null && $v !== '');
    }
    return $rows;
}

function clean_text(string $text): string
{
    return trim((string)preg_replace('/\s+/u', ' ', $text));
}
