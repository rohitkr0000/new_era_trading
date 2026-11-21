<?php
/*
 | Where does one dashboard request spend its time?  Runs the real code paths in-process against a LOAD database:
 |   php tests\profile_bootstrap.php            (env: DB_DATABASE=nt_load ...)
 */
use App\Models\User;
use App\Services\Snapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! str_contains((string) DB::getDatabaseName(), 'load')) { fwrite(STDERR, "Refusing: not a load-test database.\n"); exit(2); }

$snap = app(Snapshot::class);
$ids = User::where('role', 'member')->where('id', '>', 1)->inRandomOrder()->limit(40)->pluck('id');   // random members, like real traffic (most have small teams)
Cache::flush();
$t = function (callable $f, int $n = 1) { $s = hrtime(true); for ($i = 0; $i < $n; $i++) $f(); return (hrtime(true) - $s) / 1e6 / $n; };

printf("members in the database: %d\n\n", User::where('role', 'member')->count());
$first = User::find($ids[0]);
printf("public numbers, cold (builds the cache):        %7.1f ms\n", $t(fn () => (new ReflectionMethod($snap, 'publicNumbers'))->invoke($snap)));
printf("public numbers, warm (from cache):               %7.1f ms\n", $t(fn () => (new ReflectionMethod($snap, 'publicNumbers'))->invoke($snap), 20));
printf("downline CTE query for one member:             %7.1f ms\n", $t(fn () => (new ReflectionMethod($snap, 'downlineIds'))->invoke($snap, $first->id), 20));

DB::enableQueryLog();
$ms = $t(fn () => $snap->member(User::find($ids[1])));
$log = DB::getQueryLog(); DB::flushQueryLog();
printf("Snapshot::member() for one user (warm net.):   %7.1f ms, %d queries (%.1f ms in the database)\n", $ms, count($log), array_sum(array_column($log, 'time')));
usort($log, fn ($a, $b) => $b['time'] <=> $a['time']);
foreach (array_slice($log, 0, 6) as $q) printf("    %6.1f ms  %s\n", $q['time'], substr(preg_replace('/\s+/', ' ', $q['query']), 0, 120));

$ms = $t(function () use ($ids, $snap) { foreach ($ids as $id) $snap->member(User::find($id)); }, 1) / count($ids);
printf("\nSnapshot::member(), average over %d different users: %7.1f ms\n", count($ids), $ms);
$ms = $t(function () use ($ids, $snap) { foreach ($ids as $id) json_encode($snap->member(User::find($id))); }, 1) / count($ids);
printf("...plus JSON encoding:                                %7.1f ms\n", $ms);
$size = strlen(json_encode($snap->member(User::find($ids[2]))));
printf("typical payload: %d bytes\n", $size);
printf("Settings::all() x100:                          %7.2f ms each\n", $t(fn () => App\Support\Settings::all(), 100));
printf("Market::status() x100:                         %7.2f ms each\n", $t(fn () => App\Services\Market::status(), 100));
printf("token lookup + user (auth) x20:                %7.2f ms each\n", $t(fn () => User::find($ids[3]), 20));
