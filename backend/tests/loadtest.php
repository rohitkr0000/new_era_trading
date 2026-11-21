<?php
/*
 | Load test for the API (curl_multi, no extra tools). It signs in real members, then hammers the endpoints
 | a busy day would hit. It only READS (no money moves) except the sign-in calls.
 |
 |   php tests\loadtest.php [baseUrl] [concurrency] [seconds]
 |   php tests\loadtest.php http://127.0.0.1:8000/api 20 20
 |
 | Run it against a COPY of the system (a throw-away database), never your live site: sign-ins are rate-limited
 | on purpose, so the test logs in once per virtual user and then reuses the tokens.
 | Prints requests/second, error rate and p50 / p95 / p99 latency per endpoint.
 */
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000/api', '/');
$conc = max(1, (int) ($argv[2] ?? 20));
$secs = max(5, (int) ($argv[3] ?? 20));
$user = getenv('LOAD_EMAIL') ?: 'demo@mlm.com';
$pass = getenv('LOAD_PASS') ?: '';
if ($pass === '') { echo "Set LOAD_EMAIL and LOAD_PASS (a member of the TEST database) in the environment first.\n"; exit(1); }

function http(string $method, string $url, ?array $body = null, ?string $token = null): array {
    $ch = curl_init($url);
    $h = ['Accept: application/json'];
    if ($token) $h[] = "Authorization: Bearer $token";
    if ($body) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $out, true)];
}

echo "Target: $base   concurrency: $conc   duration: {$secs}s\n";
[$c, $j] = http('POST', "$base/login", ['email' => $user, 'password' => $pass]);
if ($c !== 200 || empty($j["token"])) { echo "Cannot sign in as $user (HTTP $c, no token in the answer). Is the server running Laravel with the demo data?
"; exit(1); }
$token = $j['token'];

$mix = [   // [label, method, path, needs token, weight]
    ['GET /public (home page)', 'GET', '/public', false, 30],
    ['GET /bootstrap (member dashboard)', 'GET', '/bootstrap', true, 50],
    ['GET /public/sponsor/NET1001', 'GET', '/public/sponsor/NET1001', false, 10],
    ['GET /bootstrap (bad token → 401)', 'GET', '/bootstrap', 'bad', 10],
];
$pool = [];
foreach ($mix as $m) for ($i = 0; $i < $m[4]; $i++) $pool[] = $m;

$stats = [];
$mh = curl_multi_init();
$active = [];
$start = microtime(true);
$total = 0;

$launch = function () use (&$active, $mh, $pool, $base, $token) {
    $m = $pool[array_rand($pool)];
    $ch = curl_init($base . $m[2]);
    $h = ['Accept: application/json'];
    if ($m[3] === true) $h[] = "Authorization: Bearer $token";
    if ($m[3] === 'bad') $h[] = 'Authorization: Bearer 999|notarealtoken';
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    curl_multi_add_handle($mh, $ch);
    $active[(int) $ch] = [$ch, $m[0], $m[3] === 'bad', microtime(true)];
};

for ($i = 0; $i < $conc; $i++) $launch();
while ($active) {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.05);
    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        [, $label, $expect401, $t0] = $active[(int) $ch];
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ms = (microtime(true) - $t0) * 1000;
        $body = (string) curl_multi_getcontent($ch);
        $ok = $info["result"] === CURLE_OK && ($expect401 ? $code === 401 : ($code === 200 && substr($body, 0, 1) === "{"));
        $stats[$label][] = [$ms, $ok];
        $total++;
        curl_multi_remove_handle($mh, $ch); curl_close($ch); unset($active[(int) $ch]);
        if (microtime(true) - $start < $secs) $launch();
    }
}
$elapsed = microtime(true) - $start;
curl_multi_close($mh);

$pct = fn (array $a, float $p) => $a[(int) min(count($a) - 1, floor($p * count($a)))];
printf("\n%-38s %7s %7s %8s %8s %8s %8s\n", 'endpoint', 'count', 'errors', 'p50 ms', 'p95 ms', 'p99 ms', 'max ms');
$allErr = 0;
foreach ($stats as $label => $rows) {
    $lat = array_column($rows, 0); sort($lat);
    $err = count(array_filter($rows, fn ($r) => ! $r[1])); $allErr += $err;
    printf("%-38s %7d %7d %8.0f %8.0f %8.0f %8.0f\n", $label, count($rows), $err, $pct($lat, .50), $pct($lat, .95), $pct($lat, .99), end($lat));
}
printf("\nTotal: %d requests in %.1fs = %.1f req/s, %d errors (%.2f%%)\n", $total, $elapsed, $total / $elapsed, $allErr, 100 * $allErr / max(1, $total));
exit($allErr ? 1 : 0);
