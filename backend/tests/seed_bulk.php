<?php
/*
 | Adds N fake members (with an order and some income rows each) for load testing — directly in SQL, fast.
 |   php tests\seed_bulk.php 2000
 | Refuses to run unless the database name contains "load" (never touch real data).
 */
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (! str_contains((string) DB::getDatabaseName(), 'load')) { fwrite(STDERR, "Refusing: database '" . DB::getDatabaseName() . "' is not a load-test database.\n"); exit(2); }
$n = max(1, (int) ($argv[1] ?? 1000));

$hash = password_hash(bin2hex(random_bytes(12)), PASSWORD_BCRYPT, ['cost' => 4]);   // nobody logs in as these fake members: the tests use tokens
$ids = DB::table('users')->where('role', 'member')->pluck('id')->all();
$next = (int) DB::table('users')->max('id');
$phone = 9100000000 + (int) DB::table('users')->count();
$t0 = microtime(true);

for ($done = 0; $done < $n; $done += 500) {
    $rows = [];
    $take = min(500, $n - $done);
    for ($i = 0; $i < $take; $i++) {
        $id = ++$next;
        $sponsor = $ids[array_rand($ids)];
        $amt = [5, 10, 25, 50, 100][array_rand([5, 10, 25, 50, 100])];
        $rows[] = ['id' => $id, 'name' => 'Load User ' . $id, 'email' => "load$id@example.net", 'password' => $hash, 'code' => 'NET' . (1000 + $id), 'role' => 'member',
            'phone' => (string) ++$phone, 'phone_key' => (string) $phone, 'sponsor_id' => $sponsor, 'parent_id' => $sponsor, 'leg' => 'left',
            'invested' => $amt, 'wallet' => 0, 'purchase_wallet' => 0, 'joined_at' => now()->subDays(random_int(0, 60)), 'email_verified_at' => now(), 'kyc_status' => 'approved',
            'created_at' => now(), 'updated_at' => now()];
        $ids[] = $id;
    }
    DB::table('users')->insert($rows);
    $orders = []; $ledger = [];
    foreach ($rows as $r) {
        $orders[] = ['ref' => 'LD-' . $r['id'], 'user_id' => $r['id'], 'amount' => $r['invested'], 'kind' => 'join', 'option' => 'Investment', 'pct' => 5, 'days' => 0, 'steps' => 0, 'paid_days' => 5, 'status' => 'running', 'created_at' => $r['joined_at']];
        for ($k = 0; $k < 5; $k++) $ledger[] = ['user_id' => $r['id'], 'type' => 'roi', 'amount' => round($r['invested'] * .05, 2), 'note' => 'ROI', 'created_at' => now()->subDays($k)];
    }
    foreach (array_chunk($orders, 250) as $c) DB::table('orders')->insert($c);
    foreach (array_chunk($ledger, 500) as $c) DB::table('ledger')->insert($c);
}
printf("Added %d members (+orders, ledger rows) in %.1fs. Members now: %d\n", $n, microtime(true) - $t0, DB::table('users')->where('role', 'member')->count());
