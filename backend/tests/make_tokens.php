<?php
/*
 | Creates login tokens for N existing members of a LOAD-TEST database, one per virtual user (so the test exercises
 | per-user throttling and per-user caching like real traffic). Refuses unless the database name contains "load".
 |   php tests\make_tokens.php 1000 D:\path\tokens.txt
 */
use App\Models\User;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (! str_contains((string) DB::getDatabaseName(), 'load')) { fwrite(STDERR, "Refusing: database '" . DB::getDatabaseName() . "' is not a load-test database.\n"); exit(2); }
$n = max(1, (int) ($argv[1] ?? 1000));
$file = $argv[2] ?? (__DIR__ . '/tokens.txt');

$ids = User::where('role', 'member')->where('id', '>', 1)->inRandomOrder()->limit($n)->pluck('id');
$lines = [];
foreach ($ids as $id) $lines[] = User::find($id)->createToken('load', ['*'], now()->addHours(6))->plainTextToken;
file_put_contents($file, implode("\n", $lines) . "\n");
echo count($lines) . " tokens written to $file\n";
