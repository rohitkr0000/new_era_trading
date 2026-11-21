<?php
/*
 | "1000 people online at once" load test.  Every virtual user has its OWN login token and its own keep-alive connection, and
 | keeps working for the whole test: load a page (dashboard data), think for a while, load again …
 |
 |   php tests\loadtest_users.php <baseUrl> <tokensFile> <users> <seconds> <thinkMinMs> <thinkMaxMs>
 |   php tests\loadtest_users.php http://127.0.0.1:8091/api tokens.txt 1000 60 15000 45000     (realistic: a member opens a page every 15-45 s)
 |   php tests\loadtest_users.php http://127.0.0.1:8091/api tokens.txt 1000 60 1000 3000       (STRESS: everybody reloads every 1-3 s)
 |
 | Prints throughput, error rate and p50 / p95 / p99 latency per request type. Read-only: no money moves.
 */
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8091/api', '/');
$tokens = array_values(array_filter(array_map('trim', @file($argv[2] ?? 'tokens.txt') ?: [])));
if (! $tokens) { echo "No tokens. Run tests\\make_tokens.php first.\n"; exit(1); }
$users = min((int) ($argv[3] ?? 1000), count($tokens));
$secs = max(10, (int) ($argv[4] ?? 60));
$tmin = (int) ($argv[5] ?? 15000); $tmax = max($tmin, (int) ($argv[6] ?? 45000));

$actions = [   // [label, path, needs token, weight]
    ['dashboard  GET /bootstrap', '/bootstrap', true, 80],
    ['home page  GET /public', '/public', false, 20],
    ['sign-up    GET /public/sponsor', '/public/sponsor/NET1001', false, 0]   // per-IP limited on purpose; all virtual users share one IP, so it is left out,
];
$pool = [];
foreach ($actions as $i => $a) for ($k = 0; $k < $a[3]; $k++) $pool[] = $i;

$mh = curl_multi_init();
$U = [];
$start = microtime(true);
for ($i = 0; $i < $users; $i++) {
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TCP_NODELAY => true, CURLOPT_ENCODING => 'gzip']);
    $U[$i] = ['ch' => $ch, 'token' => $tokens[$i], 'next' => $start + mt_rand(0, 10000) / 1000, 'busy' => false, 'act' => 0];   // ramp up over 10 s
}
$byCh = [];
$stat = []; $codes = []; $sent = 0; $done = 0; $inflightMax = 0; $buckets = [];
$end = $start + $secs;

while (true) {
    $now = microtime(true);
    if ($now < $end) {
        foreach ($U as $i => &$u) {
            if ($u['busy'] || $u['next'] > $now) continue;
            $a = $pool[array_rand($pool)];
            $u['act'] = $a;
            $h = ['Accept: application/json'];
            if ($actions[$a][2]) $h[] = 'Authorization: Bearer ' . $u['token'];
            curl_setopt_array($u['ch'], [CURLOPT_URL => $base . $actions[$a][1], CURLOPT_HTTPHEADER => $h]);
            curl_multi_add_handle($mh, $u['ch']);
            $u['busy'] = true; $byCh[(int) $u['ch']] = $i; $sent++;
        }
        unset($u);
    }
    curl_multi_exec($mh, $running);
    $inflightMax = max($inflightMax, $running);
    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle']; $i = $byCh[(int) $ch];
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ms = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
        $body = (string) curl_multi_getcontent($ch);
        $a = $U[$i]['act'];
        $ok = $info['result'] === CURLE_OK && $code === 200 && substr($body, 0, 1) === '{';
        $stat[$a][] = [$ms, $ok]; $done++;
        $key = $info['result'] === CURLE_OK ? (string) $code : 'curl:' . $info['result'];
        $codes[$key] = ($codes[$key] ?? 0) + 1;
        $b = (int) floor((microtime(true) - $start) / 10); $buckets[$b][] = $ms;
        curl_multi_remove_handle($mh, $ch);
        $U[$i]['busy'] = false; $U[$i]['next'] = microtime(true) + mt_rand($tmin, $tmax) / 1000;
        unset($byCh[(int) $ch]);
    }
    if (microtime(true) >= $end && $running === 0 && ! $byCh) break;
    if (microtime(true) > $end + 45) break;   // never hang
    curl_multi_select($mh, 0.01);
}
$elapsed = microtime(true) - $start;
foreach ($U as $u) curl_close($u['ch']);

$pct = fn (array $a, float $p) => $a[(int) min(count($a) - 1, floor($p * count($a)))];
printf("\n%d virtual users online (max %d requests in flight), think time %d-%d s, %.0f s\n", $users, $inflightMax, $tmin / 1000, $tmax / 1000, $elapsed);
printf("%-32s %7s %7s %8s %8s %8s %8s\n", 'request', 'count', 'errors', 'p50 ms', 'p95 ms', 'p99 ms', 'max ms');
$allErr = 0; $all = [];
foreach ($actions as $a => $act) {
    if (empty($stat[$a])) continue;
    $rows = $stat[$a] ?? []; if (! $rows) continue;
    $lat = array_column($rows, 0); sort($lat); $all = array_merge($all, $lat);
    $err = count(array_filter($rows, fn ($r) => ! $r[1])); $allErr += $err;
    printf("%-32s %7d %7d %8.0f %8.0f %8.0f %8.0f\n", $act[0], count($rows), $err, $pct($lat, .50), $pct($lat, .95), $pct($lat, .99), end($lat));
}
sort($all);
printf("%-32s %7d %7d %8.0f %8.0f %8.0f %8.0f\n", 'ALL', $done, $allErr, $pct($all, .50), $pct($all, .95), $pct($all, .99), end($all));
printf("throughput %.1f requests/s, errors %d (%.2f%%)\n", $done / $elapsed, $allErr, 100 * $allErr / max(1, $done));
echo 'HTTP results: ' . json_encode($codes) . "\n";
ksort($buckets);
echo "p95 latency per 10 s window (ms): ";
foreach ($buckets as $b => $l) { sort($l); echo ($b * 10) . 's=' . round($pct($l, .95)) . ' '; }
echo "\n";
exit($allErr ? 1 : 0);
