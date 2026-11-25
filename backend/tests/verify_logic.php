<?php
/*
 | Plan-logic verification for New Era Trading.
 |
 | Run  tests\verify.bat   (it creates a throw-away SQLite file — your real MariaDB data is never touched).
 | Manual run from the backend folder:
 |   $env:DB_CONNECTION='sqlite'; $env:DB_DATABASE="$PWD\database\verify.sqlite"; New-Item $env:DB_DATABASE -Force | Out-Null
 |   C:\xampp\php\php.exe artisan migrate:fresh --force ; C:\xampp\php\php.exe tests\verify_logic.php
 */

use App\Models\User;
use App\Services\Market;
use App\Services\Notifier;
use App\Services\OtpService;
use App\Services\PlanEngine;
use App\Services\WalletService;
use App\Services\ChainVerifier;
use App\Support\Audit;
use App\Support\Money;
use App\Support\Phone;
use App\Support\PlanException;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (DB::getDriverName() !== 'sqlite') {
    fwrite(STDERR, "Refusing to run: this script must use the throw-away SQLite database (see the header). Current driver: " . DB::getDriverName() . "\n");
    exit(2);
}

$plan = app(PlanEngine::class);
$wallet = app(WalletService::class);
$otp = app(OtpService::class);
$pass = 0; $fail = 0; $n = 0;

function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $name . ($ok ? '' : "   -> $detail") . "\n";
}
function eq(string $name, $actual, $expected): void {
    // money values may come back as 70 (SQLite) or "70.00" (MariaDB DECIMAL): compare them as exact 2-decimal amounts
    $same = (is_numeric($actual) && is_numeric($expected) && str_contains((string) $expected, '.')) ? Money::r($actual) === Money::r($expected) : (string) $actual === (string) $expected;
    check($name, $same, "got $actual, expected $expected");
}
function throws(string $name, callable $fn, string $contains): void {
    try { $fn(); check($name, false, 'no error thrown'); }
    catch (PlanException $e) { check($name, str_contains($e->getMessage(), $contains), 'message: ' . $e->getMessage()); }
}
function section(string $t): void { echo "\n== $t ==\n"; }
function at(string $utc): void { Carbon::setTestNow(Carbon::parse($utc, 'UTC')); }
function sumType(int $uid, string $type): string { return Money::r(DB::table('ledger')->where('user_id', $uid)->where('type', $type)->sum('amount')); }

$mk = function (string $tag, ?User $sponsor, $amount = null) use ($plan, &$n) {
    $n++;
    return $plan->register([
        'name' => "T $tag", 'email' => "t_$tag@test.local", 'password' => 'secret1', 'phone' => '98' . str_pad((string) $n, 8, '0', STR_PAD_LEFT),
        'sponsorCode' => $sponsor?->code, 'amount' => $amount,
    ], $sponsor === null);
};

DB::beginTransaction();
try {
    Settings::flush();
    $S = Settings::all();
    $MON = '2026-11-16 06:30:00';   // Monday 12:00 IST (IST = UTC+5:30)

    section('Plan settings');
    eq('ROI % per trading day', $S['roiPct'], 5); eq('direct %', $S['directPct'], 5); eq('retopup %', $S['retopupPct'], 5);
    eq('level % (L1–L12): L1 2%, L2 1%, L3–L12 0.2% each', implode(',', $S['levelPct']), '2,1,0.2,0.2,0.2,0.2,0.2,0.2,0.2,0.2,0.2,0.2');
    eq('the 12 levels add up to 5% of an investment', array_sum($S['levelPct']), 5);
    eq('caps 2X / 5X', $S['capNonWorking'] . '/' . $S['capWorking'], '2/5');
    eq('5X rule: level-1 business must reach 5 x own investment', $S['capMode'] . ' / ' . $S['capBusinessMultiple'], 'business / 5');
    eq('12 active directs unlock all 12 levels', $S['capDirectsRequired'], 12);
    eq('a package earns ROI for 25 trading days', $S['roiDays'], 25);
    eq('withdrawal charge (company sheet)', $S['withdrawFeePct'], 10);
    eq('joining multiple / min join', $S['joinMultiple'] . '/' . $S['minJoin'], '5/5');
    eq('withdraw min / multiple', $S['minWithdraw'] . '/' . $S['withdrawMultiple'], '5/5');
    eq('withdrawal window', $S['withdrawStart'] . '-' . $S['withdrawEnd'], '10-14'); eq('currency', $S['currency'], '$');

    section('Amounts — $5 minimum, multiples of $5 only');
    at($MON);
    $root0 = $mk('amtroot', null, 100);
    foreach ([5, 10, 15, 25, 100] as $ok) {
        $m = $mk("amt$ok", $root0, $ok);
        check("joining with \$$ok is accepted", Money::r($m->invested) === Money::r($ok));
    }
    throws('$4 is below the minimum', fn () => $mk('amt4', $root0, 4), 'Minimum');
    foreach ([7, 12, 18, 22.5, 101] as $bad) throws("joining with \$$bad is rejected (not a multiple of 5)", fn () => $mk('bad' . str_replace('.', '_', (string) $bad), $root0, $bad), 'multiples of');
    $someone = $mk('retop', $root0, 10);
    throws('retopup $12 is rejected', fn () => $plan->invest($someone->id, 12), 'multiples of');
    throws('retopup $3 is rejected', fn () => $plan->invest($someone->id, 3), 'Minimum');
    check('retopup $15 is accepted', (bool) $plan->invest($someone->id, 15));

    section('One account per email and per mobile number');
    $A = $plan->register(['name' => 'A', 'email' => 'dup_a@test.local', 'password' => 'secret1', 'phone' => '9876543210', 'sponsorCode' => $root0->code], false);
    check('a new member gets a random 6-digit ID, no "NET" prefix', (bool) preg_match('/^\d{6}$/', $A->code), $A->code);
    throws('same email is rejected', fn () => $plan->register(['name' => 'B', 'email' => 'dup_a@test.local', 'password' => 'secret1', 'phone' => '9123456780', 'sponsorCode' => $root0->code], false), 'email is already');
    throws('same email in different case is rejected', fn () => $plan->register(['name' => 'B', 'email' => 'DUP_A@Test.Local', 'password' => 'secret1', 'phone' => '9123456780', 'sponsorCode' => $root0->code], false), 'email is already');
    throws('same mobile number is rejected', fn () => $plan->register(['name' => 'B', 'email' => 'dup_b@test.local', 'password' => 'secret1', 'phone' => '9876543210', 'sponsorCode' => $root0->code], false), 'mobile number is already');
    // only a bare 10-digit number is accepted now — no +91, spaces, dashes or letters (report requirement #8)
    foreach (['+91 98765 43210', '09876543210', '98765-43210', '+91-9876543210', 'abc1234567', '98765432100', '987654321'] as $fmt) {
        throws("badly formatted number \"$fmt\" is rejected (10 digits only)", fn () => $plan->register(['name' => 'B', 'email' => 'dup_' . md5($fmt) . '@test.local', 'password' => 'secret1', 'phone' => $fmt, 'sponsorCode' => $root0->code], false), 'valid 10-digit mobile number');
    }
    throws('missing mobile number is rejected', fn () => $plan->register(['name' => 'B', 'email' => 'dup_c@test.local', 'password' => 'secret1', 'phone' => '', 'sponsorCode' => $root0->code], false), 'mobile number');
    throws('too-short mobile number is rejected', fn () => $plan->register(['name' => 'B', 'email' => 'dup_d@test.local', 'password' => 'secret1', 'phone' => '12345', 'sponsorCode' => $root0->code], false), 'mobile number');
    check('a different mobile + email registers fine', (bool) $plan->register(['name' => 'C', 'email' => 'dup_e@test.local', 'password' => 'secret1', 'phone' => '9123456780', 'sponsorCode' => $root0->code], false));
    eq('Phone::key normalises +91 / 0 prefixes', Phone::key('+91 98765 43210') . '|' . Phone::key('09876543210'), '9876543210|9876543210');

    section('Member IDs are random and unique (report requirement #7)');
    $codes = [];
    for ($i = 0; $i < 25; $i++) { $m = $mk('idfmt' . $i, $root0, 5); $codes[] = $m->code; }
    check('every generated code is a plain 6-digit number', ! array_filter($codes, fn ($c) => ! preg_match('/^\d{6}$/', $c)), implode(',', $codes));
    check('25 freshly generated codes are all unique', count($codes) === count(array_unique($codes)));
    try { DB::table('users')->insert(['name' => 'x', 'email' => 'raw@test.local', 'password' => 'x', 'phone_key' => '9876543210', 'role' => 'member']); check('database unique index blocks a duplicate mobile', false, 'insert succeeded'); }
    catch (\Illuminate\Database\UniqueConstraintViolationException $e) { check('database unique index blocks a duplicate mobile (race-proof)', true); }
    throws('invalid sponsor code is rejected', fn () => $plan->register(['name' => 'x', 'email' => 'x@test.local', 'password' => 'secret1', 'phone' => '9000000001', 'sponsorCode' => 'NOPE99'], false), 'Invalid sponsor');

    section('ROI cycle — every package earns for 25 trading days, then expires');
    at($MON);
    $CY = $mk('cycle', null, 100);                      // $100 → $5 per trading day
    at('2027-03-01 06:31:00');                          // ~15 weeks, far more than 25 trading days
    $plan->accrue();
    eq('25 ROI credits only', DB::table('ledger')->where('user_id', $CY->id)->where('type', 'roi')->count(), 25);
    eq('simple interest: 25 x $5 = $125.00 (the spreadsheet: SI_25 Days)', sumType($CY->id, 'roi'), '125.00');
    eq('the package is closed after its cycle', DB::table('orders')->where('user_id', $CY->id)->value('status'), 'closed');
    $beforeCy = DB::table('ledger')->count();
    $plan->accrue();
    eq('a closed package never pays again', DB::table('ledger')->count() - $beforeCy, 0);

    section('Earning cap — 2X by default, 5X when the level-1 business reaches 5 x own investment');
    Settings::update(['roiDays' => 0]);                 // cycle off here so that the cap itself can be reached
    at($MON);
    $N = $mk('cap', null, 10);                          // invested $10 → 2X cap = $20, ROI $0.50 / trading day
    at('2027-03-01 06:31:00');                          // ~15 weeks ≈ 75 trading days
    $plan->accrue();
    $c = $plan->capInfo($N->fresh());
    eq('no referrals → 2X', $c['mult'], 2); eq('cap = $20.00', $c['cap'], '20.00');
    eq('income stops exactly at the cap', $plan->incomeTotal($N->id), '20.00');
    eq('wallet equals capped income', $N->fresh()->wallet, '20.00');
    at('2027-03-01 07:00:00');
    $dirs = [$mk('capd1', $N, 5)];
    eq('level-1 business $5 of the $50 needed → still 2X', $plan->capInfo($N->fresh())['mult'], 2);
    eq('no direct income while capped', sumType($N->id, 'direct'), '0.00');
    for ($i = 2; $i <= 9; $i++) $dirs[] = $mk("capd$i", $N, 5);
    $c9 = $plan->capInfo($N->fresh());
    check('9 directs = $45 of $50 → still 2X', (float) $c9['mult'] === 2.0 && $c9['directBusiness'] === '45.00' && $c9['businessNeeded'] === '50.00', json_encode($c9));
    $dirs[] = $mk('capd10', $N, 5);
    $c10 = $plan->capInfo($N->fresh());
    check('10 directs = $50 = 5 x $10 → 5X, cap $50', (float) $c10['mult'] === 5.0 && $c10['cap'] === '50.00' && $c10['working'], json_encode($c10));
    eq('the 10th direct pays 5% direct income ($0.25) because the cap is now $50', sumType($N->id, 'direct'), '0.25');
    at('2027-03-03 07:30:00');
    $plan->accrue();
    check('ROI resumes above the old $20 cap', Money::cmp($plan->incomeTotal($N->id), '20.25') > 0, 'income ' . $plan->incomeTotal($N->id));
    check('and never exceeds the new 5X cap ($50)', Money::cmp($plan->incomeTotal($N->id), '50.00') <= 0, 'income ' . $plan->incomeTotal($N->id));
    // the rule is about money, not head-count
    $BIG = $mk('capbig', null, 20);                     // needs $100 of level-1 business
    $one = $mk('capbig1', $BIG, 100);
    check('ONE direct who invested $100 = 5 x $20 is enough (no 12-direct rule any more)', $plan->isWorking($BIG->id) && (float) $plan->capInfo($BIG->fresh())['mult'] === 5.0);
    DB::table('users')->where('id', $one->id)->update(['blocked' => true]);
    check('a blocked direct no longer counts', ! $plan->isWorking($BIG->id));
    DB::table('users')->where('id', $one->id)->update(['blocked' => false]);
    $plan->invest($BIG->id, 20);                        // own investment doubles to $40 -> $200 needed
    check('investing more raises the target: $100 of $200 needed -> back to 2X', ! $plan->isWorking($BIG->id));
    Settings::update(['capMode' => 'directs']);
    check('capMode "directs": one big direct is NOT enough', ! $plan->isWorking($BIG->id));
    check('capMode "directs": 10 directs are still not 12', ! $plan->isWorking($N->id));
    Settings::update(['capMode' => 'business', 'roiDays' => 25]);

    section('Direct income 5% + retopup income 5% + level 1 (2%)');
    at($MON);
    $S1 = $mk('sp', null, 100);
    $M1 = $mk('m1', $S1, 50);
    eq('sponsor earns 5% of joining $50 = $2.50 direct', sumType($S1->id, 'direct'), '2.50');
    eq('plus level-1 income 2% of $50 = $1.00', sumType($S1->id, 'level'), '1.00');
    $plan->invest($M1->id, 20);
    eq('sponsor earns 5% of retopup $20 = $1.00 retopup income', sumType($S1->id, 'retopup'), '1.00');
    eq('level-1 income also on the retopup: 1.00 + 0.40', sumType($S1->id, 'level'), '1.40');
    eq('retopup does NOT pay "direct" again', DB::table('ledger')->where('user_id', $S1->id)->where('type', 'direct')->count(), 1);
    eq('member invested total', $M1->fresh()->invested, '70.00');
    // the example from the rules: 100 members at level 1, each $5 → $500 × 2% = $10
    $Z = $mk('z', null, 100);
    for ($i = 1; $i <= 100; $i++) $plan->register(['name' => "Z$i", 'email' => "z$i@test.local", 'password' => 'secret1', 'phone' => '97' . str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'sponsorCode' => $Z->code, 'amount' => 5], false);
    eq('100 direct members × $5 → level-1 income $10.00 (2% of $500)', sumType($Z->id, 'level'), '10.00');
    eq('…and direct income 5% of $500 = $25.00', sumType($Z->id, 'direct'), '25.00');

    section('12-level income — unlocked level by level (level k needs k active directs)');
    at($MON);
    // P has 3 directs; X1 has a chain below it: P → X1 (L1) → Y2 (L2) → Y3 (L3) → Y4 (L4) → Y5 (L5)
    $P = $mk('p', null, 100);
    $X = [$mk('x1', $P, 100), $mk('x2', $P, 100), $mk('x3', $P, 100)];
    $Y2 = $mk('y2', $X[0], 100); $Y3 = $mk('y3', $Y2, 100); $Y4 = $mk('y4', $Y3, 100); $Y5 = $mk('y5', $Y4, 100);
    eq('3 directs → levels 1–3 paid: 3×$2 + $1 + $0.20 = $7.20 (levels 4 and 5 are locked)', sumType($P->id, 'level'), '7.20');
    $X4 = $mk('x4', $P, 100);
    eq('4th direct joins → +$2 level 1', sumType($P->id, 'level'), '9.20');
    $plan->invest($Y4->id, 100);
    eq('now level 4 is unlocked: Y4 retopup pays 0.2% = $0.20', sumType($P->id, 'level'), '9.40');
    $plan->invest($Y5->id, 100);
    eq('level 5 still locked (needs 5 directs): no change', sumType($P->id, 'level'), '9.40');

    // R has 12 directs, then a chain 13 levels deep
    $R = $mk('r', null, 100);
    $D = []; for ($i = 1; $i <= 12; $i++) $D[] = $mk("d$i", $R, 100);
    $chain = [$D[0]]; for ($lvl = 2; $lvl <= 13; $lvl++) $chain[] = $mk("c$lvl", end($chain), 100);   // chain[0]=L1 … chain[11]=L12, chain[12]=L13
    $exp = '0.00';
    for ($i = 1; $i <= 12; $i++) $exp = Money::add($exp, Money::pct(100, $S['levelPct'][0]));            // D1..D12 at level 1
    for ($lvl = 2; $lvl <= 12; $lvl++) $exp = Money::add($exp, Money::pct(100, $S['levelPct'][$lvl - 1])); // chain levels 2..12
    eq('12 directs → all 12 levels paid: 12×$2 + $1 + 10×$0.20 = $27.00', sumType($R->id, 'level'), $exp);
    eq('expected total is $27.00', $exp, '27.00');
    eq('level 13 pays nothing', DB::table('ledger')->where('user_id', $R->id)->where('from_id', $chain[12]->id)->where('type', 'level')->count(), 0);
    eq('each level pays the right amount (L12 = $0.20)', DB::table('ledger')->where('user_id', $R->id)->where('from_id', $chain[11]->id)->where('type', 'level')->value('amount'), '0.20');
    eq('direct income from 12 directs = 12 × $5', sumType($R->id, 'direct'), '60.00');

    section('Level income is on investments, NOT on ROI');
    $lvBefore = DB::table('ledger')->where('type', 'level')->count();
    at('2026-11-17 06:31:00');                          // Tuesday: first ROI day for everyone created Monday
    $plan->accrue();
    eq('running the daily ROI creates no level income', DB::table('ledger')->where('type', 'level')->count() - $lvBefore, 0);
    eq('but ROI is credited ($5.00 on $100)', Money::r(DB::table('ledger')->where('user_id', $R->id)->where('type', 'roi')->orderBy('id')->value('amount')), '5.00');

    section('ROI only on trading days (Mon–Fri)');
    at($MON);
    $W = $mk('wk', null, 100);
    at('2026-11-23 06:31:00');                          // +7 days: Tue Wed Thu Fri (4) + Sat Sun (off) + Mon (1) = 5 paid
    $plan->accrue();
    $rois = DB::table('ledger')->where('user_id', $W->id)->where('type', 'roi')->get();
    eq('7 calendar days → 5 ROI credits', $rois->count(), 5);
    eq('no ROI dated on Saturday/Sunday (IST)', $rois->filter(fn ($r) => ! Market::isTradingDay(Carbon::parse($r->created_at, 'UTC')))->count(), 0);
    $before = DB::table('ledger')->count();
    $plan->accrue();
    eq('running accrue again credits nothing (idempotent)', DB::table('ledger')->count() - $before, 0);

    section('ROI lands at the same midnight for everyone, whatever time of day they bought');
    at('2026-11-24 00:35:00');                          // Tue 06:05 IST
    $early = $mk('early', null, 100);
    at('2026-11-24 18:00:00');                          // Tue 23:30 IST — same IST calendar day, 17+ hours later
    $late = $mk('late', null, 100);
    at('2026-11-25 01:00:00');                          // Wed 06:30 IST — the midnight both are waiting for has now passed
    $plan->accrue();
    $roiAt = fn (int $uid) => DB::table('ledger')->where('user_id', $uid)->where('type', 'roi')->value('created_at');
    eq('both bought the same IST day → both get exactly 1 ROI credit', DB::table('ledger')->where('user_id', $early->id)->where('type', 'roi')->count() . '/' . DB::table('ledger')->where('user_id', $late->id)->where('type', 'roi')->count(), '1/1');
    eq('credited at the identical instant (next midnight IST), not 24h after each purchase', $roiAt($early->id), $roiAt($late->id));

    section('Withdrawals — $5 multiples, Mon–Fri 10 AM–2 PM IST, 24h receipt');
    $m = $N->fresh();
    at('2026-11-14 06:30:00');   throws('Saturday is blocked', fn () => $wallet->requestWithdrawal($m, 10, 'USDT', '0xabc'), 'Weekend');
    at('2026-11-15 06:30:00');   throws('Sunday is blocked', fn () => $wallet->requestWithdrawal($m, 10, 'USDT', '0xabc'), 'Weekend');
    at('2026-11-16 03:30:00');   throws('Monday 9 AM IST (before the window) is blocked', fn () => $wallet->requestWithdrawal($m, 10, 'USDT', '0xabc'), 'opens today');
    at('2026-11-16 09:30:00');   throws('Monday 3 PM IST (after the window) is blocked', fn () => $wallet->requestWithdrawal($m, 10, 'USDT', '0xabc'), 'closed');
    at('2026-11-16 05:30:00');   // Monday 11:00 IST — open
    check('window is open at 11 AM IST', Market::status()['open'] === true);
    throws('$12 is not a multiple of $5', fn () => $wallet->requestWithdrawal($m, 12, 'USDT', '0xabc'), 'multiples');
    throws('$3 is below the $5 minimum', fn () => $wallet->requestWithdrawal($m, 3, 'USDT', '0xabc'), 'Minimum');
    throws('empty payout address is rejected', fn () => $wallet->requestWithdrawal($m, 10, 'USDT', ''), 'payout');
    throws('more than the wallet is rejected', fn () => $wallet->requestWithdrawal($m, 5000, 'USDT', '0xabc'), 'Insufficient');
    $w0 = $m->fresh()->wallet;
    $wid = $wallet->requestWithdrawal($m->fresh(), 10, 'USDT BEP20', '0xabc');
    check('requestWithdrawal returns the withdrawal id', is_int($wid) && $wid > 0);
    eq('valid $10 request debits the wallet', Money::sub($w0, $m->fresh()->wallet), '10.00');
    $wd = DB::table('withdrawals')->where('id', $wid)->first();
    eq('10% withdrawal charge → fee $1.00', $wd->fee, '1.00'); eq('...net = $9.00', $wd->net, '9.00'); eq('status pending', $wd->status, 'pending');
    eq('receipt due exactly 24 hours after the request (seconds)', Carbon::parse($wd->due_at)->getTimestamp() - Carbon::parse($wd->created_at)->getTimestamp(), 86400);
    $wallet->processWithdrawal($wd->id, 'rejected');
    eq('rejecting refunds the full amount (charge included)', $m->fresh()->wallet, $w0);
    throws('a processed request cannot be processed twice', fn () => $wallet->processWithdrawal($wd->id, 'approved'), 'already processed');

    section('Deposits, transfers, purchases');
    at($MON);
    $A1 = $mk('da', null, 100); $B1 = $mk('db', $A1, 50);
    $wallet->requestDeposit($A1, 100, '0xtesthash0000000001');
    throws('duplicate transaction hash rejected', fn () => $wallet->requestDeposit($A1, 50, '0xTESTHASH0000000001'), 'already submitted');
    throws('deposit below minimum rejected', fn () => $wallet->requestDeposit($A1, 3, '0xtesthash0000000002'), 'Minimum deposit');
    eq('pending deposit does not credit the wallet', $A1->fresh()->purchase_wallet, '0.00');
    $did = (int) DB::table('deposits')->where('txn_id', '0xtesthash0000000001')->value('id');
    $wallet->processDeposit($did, 'approved');
    eq('approved deposit credits the purchase wallet', $A1->fresh()->purchase_wallet, '100.00');
    throws('a deposit cannot be approved twice', fn () => $wallet->processDeposit($did, 'approved'), 'already processed');
    $wallet->transferToMember($A1->fresh(), $B1->code, 30);
    eq('transfer debits the sender the full $30', $A1->fresh()->purchase_wallet, '70.00');
    eq('the receiver gets $30 minus the 5% company charge = $28.50', $B1->fresh()->purchase_wallet, '28.50');
    eq('the charge ($1.50) is recorded as a deduction', DB::table('deductions')->where('user_id', $A1->id)->where('note', 'like', 'P2P charge%')->value('amount'), '1.50');
    $tr = DB::table('transfers')->where('from_id', $A1->id)->orderByDesc('id')->first();
    check('the transfer row keeps amount / fee / net', Money::r($tr->amount) === '30.00' && Money::r($tr->fee) === '1.50' && Money::r($tr->net) === '28.50', json_encode($tr));
    throws('cannot transfer to yourself', fn () => $wallet->transferToMember($A1->fresh(), $A1->code, 5), 'yourself');
    throws('cannot transfer more than the balance', fn () => $wallet->transferToMember($A1->fresh(), $B1->code, 999), 'Insufficient');
    $inv0 = $A1->fresh()->invested;
    $plan->purchase($A1->fresh(), 40, 'purchase');
    eq('retopup from the purchase wallet debits it', $A1->fresh()->purchase_wallet, '30.00');
    eq('invested increases by the purchase', Money::sub($A1->fresh()->invested, $inv0), '40.00');
    throws('purchase above the purchase-wallet balance rejected', fn () => $plan->purchase($A1->fresh(), 500, 'purchase'), 'Insufficient purchase');
    throws('purchase of $12 rejected (multiples of $5)', fn () => $plan->purchase($A1->fresh(), 12, 'purchase'), 'multiples of');
    throws('purchase below $5 rejected', fn () => $plan->purchase($A1->fresh(), 4, 'purchase'), 'Minimum');

    section('P2P — 5% company charge, recipient shown before confirming, weekends allowed');
    $P1 = $mk('p2pa', $root0, 100); $P2 = $mk('p2pb', $root0, 100);
    DB::table('users')->where('id', $P1->id)->update(['purchase_wallet' => '200.00']);
    DB::table('wallet_ledger')->insert(['user_id' => $P1->id, 'type' => 'deposit', 'amount' => '200.00', 'balance' => '200.00', 'note' => 'test', 'created_at' => Carbon::now()]);
    $pv = $wallet->previewTransfer(User::find($P1->id), strtolower($P2->code), 100);
    eq('preview shows the receiver\'s name (confirmation step)', $pv['to']->name, $P2->name);
    eq('preview: 5% company charge on $100 = $5.00', $pv['fee'], '5.00'); eq('preview: the receiver gets $95.00', $pv['net'], '95.00');
    eq('a preview moves no money', User::find($P1->id)->purchase_wallet, '200.00');
    throws('unknown member ID is refused', fn () => $wallet->previewTransfer(User::find($P1->id), 'NET9999999', 10), 'not found');
    throws('sending to yourself is refused', fn () => $wallet->previewTransfer(User::find($P1->id), $P1->code, 10), 'yourself');
    DB::table('users')->where('id', $P2->id)->update(['blocked' => true]);
    throws('a blocked member cannot receive', fn () => $wallet->previewTransfer(User::find($P1->id), $P2->code, 10), 'not found');
    DB::table('users')->where('id', $P2->id)->update(['blocked' => false]);
    at('2026-11-21 06:30:00');   // Saturday
    check('Saturday: P2P works', (bool) $wallet->transferToMember(User::find($P1->id), $P2->code, 100));
    eq('Saturday P2P: sender -$100, receiver +$95', Money::r(User::find($P1->id)->purchase_wallet) . '/' . Money::r(User::find($P2->id)->purchase_wallet), '100.00/95.00');
    check('Saturday: buying a package (top-up) works', (bool) $plan->purchase(User::find($P2->id), 50, 'purchase'));
    check('Saturday: a new member can register', (bool) $mk('satreg', $root0, 10));

    section('P2P from the Income wallet too (report requirement #5), not only the purchase wallet');
    $I1 = $mk('p2pi1', $root0, 100); $I2 = $mk('p2pi2', $root0, 100);
    DB::table('users')->where('id', $I1->id)->update(['wallet' => '50.00']);
    DB::table('ledger')->insert(['user_id' => $I1->id, 'type' => 'roi', 'amount' => '50.00', 'note' => 'test income', 'created_at' => Carbon::now()]);
    $i1Purchase0 = $I1->fresh()->purchase_wallet; $i2Purchase0 = $I2->fresh()->purchase_wallet;
    $pvI = $wallet->previewTransfer(User::find($I1->id), $I2->code, 20, 'income');
    eq('income-wallet preview: 5% fee on $20 = $1.00', $pvI['fee'], '1.00');
    throws('sending more than the income balance is refused', fn () => $wallet->previewTransfer(User::find($I1->id), $I2->code, 999, 'income'), 'Insufficient income');
    $wallet->transferToMember(User::find($I1->id), $I2->code, 20, 'income');
    eq('sender\'s income wallet is debited the full $20', User::find($I1->id)->wallet, '30.00');
    eq('sender\'s purchase wallet is untouched', Money::r(User::find($I1->id)->purchase_wallet), Money::r($i1Purchase0));
    eq('receiver\'s purchase wallet gets $20 minus the 5% fee = $19.00', Money::sub(User::find($I2->id)->purchase_wallet, $i2Purchase0), '19.00');
    eq('the charge is recorded noting it came from the income wallet', DB::table('deductions')->where('user_id', $I1->id)->where('note', 'like', '%income wallet%')->value('amount'), '1.00');
    throws('no source given still checks the purchase wallet by default (old behaviour unchanged)', fn () => $wallet->previewTransfer(User::find($I1->id), $I2->code, 5), 'Insufficient purchase');
    at('2026-11-22 06:30:00');   // Sunday
    check('Sunday: withdrawal is refused', (function () use ($wallet, $P1) { try { $wallet->requestWithdrawal(User::find($P1->id), 5, 'USDT', '0xabc'); return false; } catch (PlanException $e) { return str_contains($e->getMessage(), 'Weekend'); } })());

    section('OTP is bound to the exact request (amount, recipient, address)');
    $O2 = $mk('otpctx', $root0, 10);
    config(['mail.default' => 'array']); app('mail.manager')->purge('array');
    $mails = fn () => app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
    at('2026-11-16 06:30:00');
    $ctxA = OtpService::context('p2p', 'NET1002', '5.00');
    $otp->send($O2, 'transfer', 'P2P transfer of $5.00 to Someone (NET1002)', $ctxA);
    preg_match('/>(\d{6})</', $mails()[count($mails()) - 1]->getOriginalMessage()->getHtmlBody(), $m1); $c1 = $m1[1];
    check('the mail names the exact transfer', str_contains($mails()[count($mails()) - 1]->getOriginalMessage()->getHtmlBody(), 'P2P transfer of $5.00 to Someone (NET1002)'));
    throws('the code cannot approve a different amount / person', fn () => $otp->verify($O2, 'transfer', $c1, OtpService::context('p2p', 'NET1002', '500.00')), 'different request');
    throws('...and that code is now burnt: even the right request is refused', fn () => $otp->verify($O2, 'transfer', $c1, $ctxA), 'Request a verification code first');
    at('2026-11-16 06:32:00');
    $otp->send($O2, 'transfer', 'P2P transfer of $5.00 to Someone (NET1002)', $ctxA);
    preg_match('/>(\d{6})</', $mails()[count($mails()) - 1]->getOriginalMessage()->getHtmlBody(), $m2); $c2 = $m2[1];
    $otp->verify($O2, 'transfer', $c2, $ctxA);
    check('the right code for the right request is accepted', true);
    at('2026-11-16 06:34:00');
    $otp->send($O2, 'withdraw', 'withdrawal', OtpService::context('wd', '10.00', 'USDT', '0xabc'));
    preg_match('/>(\d{6})</', $mails()[count($mails()) - 1]->getOriginalMessage()->getHtmlBody(), $m3); $c3 = $m3[1];
    throws('a withdrawal code cannot be used for a transfer (purposes are separate)', fn () => $otp->verify($O2, 'transfer', $c3, OtpService::context('wd', '10.00', 'USDT', '0xabc')), 'Request a verification code first');
    throws('a withdrawal code for another address is refused', fn () => $otp->verify($O2, 'withdraw', $c3, OtpService::context('wd', '10.00', 'USDT', '0xDIFFERENT')), 'different request');

    section('Compounding — the spreadsheet, day by day ($100, 5% a day, 25-day cycle)');
    $comp = app(\App\Services\CompoundService::class);
    $runDays = function (string $from, string $to) use ($comp) {   // one scheduler run per calendar day, at 12:00 IST
        for ($d = Carbon::parse($from, 'UTC'); $d->lte(Carbon::parse($to, 'UTC')); $d = $d->copy()->addDay()) { at($d->format('Y-m-d') . ' 06:30:00'); $comp->accrue(); }
    };
    at('2026-11-16 06:30:00');                                  // Monday = purchase day
    $CA = $mk('cmpa', null, null); $CB = $mk('cmpb', null, null); $CC = $mk('cmpc', null, null);
    foreach ([$CA, $CB] as $m) $plan->invest($m->id, 100, 'Compounding');
    $plan->invest($CC->id, 10, 'Compounding');
    eq('a compounding package is a lot, not cash ROI', DB::table('compound_lots')->where('user_id', $CA->id)->count(), 1);
    eq('its order is ref CMP-…', str_starts_with(DB::table('orders')->where('user_id', $CA->id)->value('ref'), 'CMP-'), true);
    $comp->accrue();
    eq('the purchase day itself earns nothing', DB::table('compound_ledger')->where('user_id', $CA->id)->count(), 0);
    $beforeAcc = DB::table('ledger')->where('user_id', $CA->id)->where('type', 'roi')->count();
    $plan->accrue();
    eq('the simple-interest job skips compounding packages', DB::table('ledger')->where('user_id', $CA->id)->where('type', 'roi')->count(), $beforeAcc);
    $runDays('2026-11-17', '2026-11-17');                        // Tuesday = day 1
    $r1 = DB::table('compound_ledger')->where('user_id', $CA->id)->orderBy('day')->first();
    check('day 1: $100 -> +$5.00 -> $105.00', Money::r($r1->opening) === '100.00' && Money::r($r1->interest) === '5.00' && Money::r($r1->closing) === '105.00', json_encode($r1));
    $runDays('2026-11-18', '2026-11-18');
    $r2 = DB::table('compound_ledger')->where('user_id', $CA->id)->orderBy('day')->get()[1];
    check('day 2: $105 -> +$5.25 -> $110.25', Money::r($r2->interest) === '5.25' && Money::r($r2->closing) === '110.25', json_encode($r2));
    $runDays('2026-11-19', '2026-11-19');
    $r3 = DB::table('compound_ledger')->where('user_id', $CA->id)->orderBy('day')->get()[2];
    check('day 3: $110.25 -> +$5.51 -> $115.76 (the sheet shows 5.51 / 115.76)', Money::r($r3->interest) === '5.51' && Money::r($r3->closing) === '115.76', json_encode($r3));
    $runDays('2026-11-20', '2026-11-20');
    $before = DB::table('compound_ledger')->where('user_id', $CA->id)->count();
    $runDays('2026-11-21', '2026-11-22');                        // Saturday + Sunday
    eq('Saturday and Sunday: no interest is calculated', DB::table('compound_ledger')->where('user_id', $CA->id)->count(), $before);
    eq('...and the lots did not age', (int) DB::table('compound_lots')->where('user_id', $CA->id)->where('is_principal', 1)->value('days_left'), 21);
    $runDays('2026-11-23', '2026-12-21');                        // through trading day 25 (Monday 21 Dec)
    $rows = DB::table('compound_ledger')->where('user_id', $CA->id)->orderBy('day')->get();
    eq('25 trading days were processed', $rows->count(), 25);
    $orderA = DB::table('orders')->where('user_id', $CA->id)->where('option', 'Compounding')->first();
    eq('day 25: the order matures instead of silently expiring', $orderA->status, 'matured');
    check('the matured total is the full compounded $338.64 — nothing is lost', abs((float) $orderA->matured_balance - 338.64) <= 0.1, 'matured ' . $orderA->matured_balance);
    eq('compounded interest is NOT income: nothing reached the income wallet', Money::r(User::find($CA->id)->wallet), '0.00');
    eq('balance() still reports the matured total (it moved, it did not vanish)', $comp->balance($CA->id), Money::r($orderA->matured_balance));
    $again = $comp->accrue();
    eq('running the job again for the same day does nothing (idempotent)', $again, 0);

    // member A: matured — re-compound its frozen total into a fresh 25-day cycle, in cash mode this time
    $comp->setMode(User::find($CA->id), 'cash');
    $rc = $comp->recompound($CA->id, $orderA->id);
    check('re-compound starts a new cycle from the matured total', abs($rc['principal'] - (float) $orderA->matured_balance) < 0.01, json_encode($rc));
    eq('the order runs again', DB::table('orders')->where('id', $orderA->id)->value('status'), 'running');
    throws('a running order cannot be re-compounded again', fn () => $comp->recompound($CA->id, $orderA->id), 'not ready to re-compound');

    // member B: matured too — re-compound (no mode switch) at the SAME moment as A, before the clock moves on
    $orderB = DB::table('orders')->where('user_id', $CB->id)->where('option', 'Compounding')->first();
    eq('member B also matured at day 25 (every order matures on its own)', $orderB->status, 'matured');
    $comp->recompound($CB->id, $orderB->id);

    $runDays('2026-12-22', '2027-01-25');                        // 25 more trading days for both new cycles at once
    $cashA = round((float) DB::table('ledger')->where('user_id', $CA->id)->where('type', 'roi')->sum('amount'), 2);
    check('cash paid on the re-compounded cycle stays within the 2X cap of $200', $cashA > 0 && $cashA <= 200.0, 'cash ' . $cashA);
    $orderB2 = DB::table('orders')->where('id', $orderB->id)->first();
    check('member B keeps compounding: ~$338.64 x 1.05^25 matures near $1146.8', $orderB2->status === 'matured' && abs((float) $orderB2->matured_balance - 1146.8) <= 8, json_encode($orderB2));
    eq('member B has still earned no cash', Money::r(User::find($CB->id)->wallet), '0.00');

    // member C: $10 package also matured (~$33.86); withdrawing it never pays the $10 principal — only growth, capped
    $orderC = DB::table('orders')->where('user_id', $CC->id)->where('option', 'Compounding')->first();
    check('member C: $10 compounded 25 days -> ~$33.86 (10 x 1.05^25)', $orderC->status === 'matured' && abs((float) $orderC->matured_balance - 33.86) <= 0.3, json_encode($orderC));
    $wdC = $comp->withdrawOrder($CC->id, $orderC->id);
    check('growth beyond the $10 principal (~$23.86) is trimmed to the 2X cap ($20)', abs($wdC['growthCredited'] - 20.0) < 0.01, json_encode($wdC));
    eq('no "principalReturned" key — the principal is never paid out', array_key_exists('principalReturned', $wdC), false);
    eq('wallet = capped growth only = $20.00 — the $10 principal was NOT paid out', Money::r(User::find($CC->id)->fresh()->wallet), '20.00');
    eq('income (capped) total sits exactly at the $20 cap', $plan->incomeTotal($CC->id), '20.00');
    eq('the order is closed after withdrawal', DB::table('orders')->where('id', $orderC->id)->value('status'), 'closed');
    throws('withdrawing an already-closed package is refused', fn () => $comp->withdrawOrder($CC->id, $orderC->id), 'already been withdrawn');

    section('Compounding — early withdrawal (member cashes out before maturity)');
    $earlyMon = Carbon::parse($MON)->addWeeks(40);
    at($earlyMon->format('Y-m-d') . ' 06:30:00');
    $CE = $mk('cmpe', null, null);
    $plan->invest($CE->id, 100, 'Compounding');
    $orderE0 = DB::table('orders')->where('user_id', $CE->id)->where('option', 'Compounding')->first();
    throws('withdrawing before any growth has accrued is refused (it would forfeit the $100 principal for $0)', fn () => $comp->withdrawOrder($CE->id, $orderE0->id), 'the principal itself is not paid out');
    at($earlyMon->copy()->addDay()->format('Y-m-d') . ' 06:30:00');   // Tuesday = day 1: $100 -> $105
    $comp->accrue();
    $orderE = DB::table('orders')->where('user_id', $CE->id)->where('option', 'Compounding')->first();
    check('still running after just 1 day', $orderE->status === 'running', $orderE->status);
    $wdE = $comp->withdrawOrder($CE->id, $orderE->id);
    check('early withdrawal reports the value reached so far (~$105), forfeiting the rest of the cycle', abs($wdE['value'] - 105.0) < 0.01, json_encode($wdE));
    eq('only the $5 growth is credited as roi — the $100 principal is not paid out', $wdE['growthCredited'], '5.00');
    eq('wallet = $5.00 only', Money::r(User::find($CE->id)->fresh()->wallet), '5.00');
    eq('the order closes immediately', DB::table('orders')->where('id', $orderE->id)->value('status'), 'closed');
    throws('a package cannot be re-compounded unless it has matured', fn () => $comp->recompound($CE->id, $orderE->id), 'not ready to re-compound');

    section('E-mail — sender "New Era Trading", registration / withdrawal / OTP messages');
    config(['mail.default' => 'array']);
    app('mail.manager')->purge('array');
    $sent = fn () => app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
    eq('configured sender name', config('mail.from.name'), 'New Era Trading');
    eq('configured sender address', config('mail.from.address'), 'no-reply@neweratrading.net');
    $U = $mk('mail', $root0, 25);
    Notifier::welcome($U);
    $msgs = $sent();
    eq('registration message was sent', count($msgs), 1);
    $m0 = $msgs[0]->getOriginalMessage();
    eq('sent from "New Era Trading"', $m0->getFrom()[0]->getName(), 'New Era Trading');
    eq('from address', $m0->getFrom()[0]->getAddress(), 'no-reply@neweratrading.net');
    eq('sent to the member', $m0->getTo()[0]->getAddress(), $U->email);
    check('welcome subject mentions New Era Trading', str_contains($m0->getSubject(), 'New Era Trading'), $m0->getSubject());
    check('welcome mail shows the member ID, mobile and the branded header', str_contains($m0->getHtmlBody(), $U->code) && str_contains($m0->getHtmlBody(), $U->phone) && str_contains($m0->getHtmlBody(), 'New Era Trading'));
    check('the logo is embedded', count($m0->getAttachments()) >= 1);
    // withdrawal mails
    at('2026-11-16 05:30:00');
    $wmember = $N->fresh();
    $wid2 = $wallet->requestWithdrawal($wmember, 5, 'USDT BEP20', '0x7Aa31F0c9bB1dE2a64C0e5F81b3d09A2c4E5f6D7');
    Notifier::withdrawalRequested($wid2);
    $wallet->processWithdrawal($wid2, 'approved');
    Notifier::withdrawalProcessed($wid2);
    $msgs = $sent();
    eq('2 more messages (request + approval)', count($msgs), 3);
    $req = $msgs[1]->getOriginalMessage(); $app = $msgs[2]->getOriginalMessage();
    check('withdrawal request mail has the reference and masks the address', str_contains($req->getSubject(), 'WD-') && str_contains($req->getHtmlBody(), '0x7Aa3…f6D7') && ! str_contains($req->getHtmlBody(), '0x7Aa31F0c9bB1dE2a64C0e5F81b3d09A2c4E5f6D7'));
    check('approval mail says approved', str_contains($app->getSubject(), 'approved'), $app->getSubject());
    eq('all mails use the same sender', $req->getFrom()[0]->getName() . '|' . $app->getFrom()[0]->getName(), 'New Era Trading|New Era Trading');
    $wid3 = $wallet->requestWithdrawal($wmember->fresh(), 5, 'USDT BEP20', '0xabcdef0123456789abcdef');
    $wallet->processWithdrawal($wid3, 'rejected'); Notifier::withdrawalProcessed($wid3);
    $rej = $sent()[3]->getOriginalMessage();
    check('rejection mail says rejected and refunded', str_contains($rej->getSubject(), 'rejected') && str_contains($rej->getHtmlBody(), 'refunded'));

    section('E-mail OTP — password change');
    app('mail.manager')->purge('array');
    at('2026-12-01 06:00:00');
    $O = $mk('otp', $root0, 5);
    $otp->send($O, 'password', 'password change');
    $m1 = $sent()[0]->getOriginalMessage();
    preg_match('/>(\d{6})</', $m1->getHtmlBody(), $mm);
    $code = $mm[1] ?? '';
    check('an OTP e-mail was sent with a 6-digit code', strlen($code) === 6, 'no code found');
    check('OTP subject starts with the code', str_starts_with($m1->getSubject(), $code), $m1->getSubject());
    eq('OTP is sent from New Era Trading', $m1->getFrom()[0]->getName(), 'New Era Trading');
    check('the code is stored hashed, not in plain text', ! DB::table('otps')->where('user_id', $O->id)->where('code_hash', $code)->exists());
    throws('a second code within a minute is refused', fn () => $otp->send($O, 'password', 'password change'), 'wait a minute');
    throws('a wrong code is rejected', fn () => $otp->verify($O, 'password', '000000' === $code ? '111111' : '000000'), 'Incorrect');
    throws('a non-numeric code is rejected', fn () => $otp->verify($O, 'password', 'abcdef'), 'Incorrect');
    $otp->verify($O, 'password', $code);
    check('the correct code is accepted', true);
    throws('a code cannot be used twice', fn () => $otp->verify($O, 'password', $code), 'Request a verification code first');
    at('2026-12-01 06:02:00');
    $otp->send($O, 'password', 'password change');
    preg_match('/>(\d{6})</', $sent()[1]->getOriginalMessage()->getHtmlBody(), $mm2); $code2 = $mm2[1];
    at('2026-12-01 06:13:00');   // 11 minutes later
    throws('an expired code (after 10 minutes) is rejected', fn () => $otp->verify($O, 'password', $code2), 'expired');
    at('2026-12-01 06:20:00');
    $otp->send($O, 'password', 'password change');
    preg_match('/>(\d{6})</', $sent()[2]->getOriginalMessage()->getHtmlBody(), $mm3); $code3 = $mm3[1];
    $wrong = $code3 === '123456' ? '654321' : '123456';
    for ($i = 0; $i < 5; $i++) { try { $otp->verify($O, 'password', $wrong); } catch (PlanException $e) {} }
    throws('after 5 wrong attempts even the right code is refused', fn () => $otp->verify($O, 'password', $code3), 'Too many');
    Notifier::passwordChanged($O);
    $all = $sent();
    check('a "password changed" security notice is sent', str_contains($all[count($all) - 1]->getOriginalMessage()->getSubject(), 'password was changed'));

    section('Deposits are checked on the blockchain');
    $COMPANY = '0x' . str_repeat('ab', 20);
    Settings::update(['depositAddress' => $COMPANY, 'autoApproveDeposits' => false, 'minDeposit' => 5]);
    $USDT = strtolower(config('services.chain.usdt'));
    $TRANSFER = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    $pad = fn (string $a) => '0x' . str_pad(strtolower(substr($a, 2)), 64, '0', STR_PAD_LEFT);
    $toHex = function (string $dec) { $h = ''; while (bccomp($dec, '0') > 0) { $h = dechex((int) bcmod($dec, '16')) . $h; $dec = bcdiv($dec, '16', 0); } return '0x' . str_pad($h ?: '0', 64, '0', STR_PAD_LEFT); };
    $receipt = fn (string $usd, string $to = null, string $status = '0x1') => ['result' => ['status' => $status, 'logs' => [[
        'address' => $USDT, 'topics' => [$TRANSFER, $pad('0x' . str_repeat('cd', 20)), $pad($to ?? $COMPANY)], 'data' => $toHex(bcmul($usd, bcpow('10', '18'), 0)),
    ]]]];
    $fake = function ($body, int $status = 200) { Http::swap(new Illuminate\Http\Client\Factory()); Http::fake(['*' => Http::response($body, $status)]); };
    $HASH = fn (int $i) => '0x' . str_pad((string) $i, 64, 'a', STR_PAD_LEFT);

    config(['services.chain.key' => '']);
    eq('no API key -> unchecked (manual review)', ChainVerifier::check($HASH(1), '10', $COMPANY)['status'], 'unchecked');
    config(['services.chain.key' => 'test-key']);
    eq('a malformed hash is refused without calling the API', ChainVerifier::check('0x123', '10', $COMPANY)['status'], 'failed');
    $fake($receipt('10.00'));
    $r = ChainVerifier::check($HASH(2), '10', $COMPANY);
    check('exact amount to the company address -> verified', $r['status'] === 'verified' && Money::r($r['amount']) === '10.00', json_encode($r));
    $fake($receipt('25.50'));
    eq('more arrived than claimed -> verified', ChainVerifier::check($HASH(3), '10', $COMPANY)['status'], 'verified');
    $fake($receipt('4.00'));
    $r = ChainVerifier::check($HASH(4), '10', $COMPANY);
    check('less arrived than claimed -> mismatch with the real amount', $r['status'] === 'mismatch' && Money::r($r['amount']) === '4.00', json_encode($r));
    $fake($receipt('10.00', '0x' . str_repeat('11', 20)));
    eq('a transfer to somebody else -> failed', ChainVerifier::check($HASH(5), '10', $COMPANY)['status'], 'failed');
    $fake($receipt('10.00', null, '0x0'));
    eq('a reverted transaction -> failed', ChainVerifier::check($HASH(6), '10', $COMPANY)['status'], 'failed');
    $fake(['result' => null]);
    eq('hash not mined yet -> unchecked (retried later)', ChainVerifier::check($HASH(7), '10', $COMPANY)['status'], 'unchecked');
    $fake('oops', 500);
    eq('API outage -> unchecked, never a false "verified"', ChainVerifier::check($HASH(8), '10', $COMPANY)['status'], 'unchecked');

    $D = $mk('dep', $root0);
    $fake($receipt('10.00'));
    $did = $wallet->requestDeposit($D, 10, $HASH(20));
    $row = DB::table('deposits')->where('id', $did)->first();
    check('a deposit is checked the moment it is submitted', $row->verified === 'verified' && $row->status === 'pending', json_encode($row));
    eq('...but not credited while auto-approve is off', Money::r(User::find($D->id)->purchase_wallet), '0.00');
    Settings::update(['autoApproveDeposits' => true]);
    $didX = $wallet->requestDeposit($D, 10, $HASH(30));
    eq('auto-approve: member with NO saved wallet -> not credited (anyone can quote a public hash)', DB::table('deposits')->where('id', $didX)->value('status'), 'pending');
    check('...and the admin is told who really sent it', str_contains((string) DB::table('deposits')->where('id', $didX)->value('verify_note'), '0x' . str_repeat('cd', 20)));
    DB::table('users')->where('id', $D->id)->update(['usdt_address' => '0x' . str_repeat('11', 20)]);
    $didY = $wallet->requestDeposit($D, 10, $HASH(31));
    eq('auto-approve: sender is a DIFFERENT wallet than the saved one -> not credited', DB::table('deposits')->where('id', $didY)->value('status'), 'pending');
    $wallet->processDeposit($didX, 'rejected'); $wallet->processDeposit($didY, 'rejected');
    DB::table('users')->where('id', $D->id)->update(['usdt_address' => '0x' . strtoupper(str_repeat('cd', 20))]);
    $did2 = $wallet->requestDeposit($D, 10, $HASH(21));
    eq('auto-approve credits a verified deposit sent from the member\'s own wallet (any letter case)', Money::r(User::find($D->id)->purchase_wallet), '10.00');
    eq('...and marks it approved', DB::table('deposits')->where('id', $did2)->value('status'), 'approved');
    $wallet->verifyDeposit($did2);
    eq('re-checking an approved deposit never credits twice', Money::r(User::find($D->id)->purchase_wallet), '10.00');
    $fake($receipt('4.00'));
    $did3 = $wallet->requestDeposit($D, 10, $HASH(22));
    eq('a mismatch is NOT auto-credited', DB::table('deposits')->where('id', $did3)->value('status'), 'pending');
    Settings::update(['autoApproveDeposits' => false]);
    Http::swap(new Illuminate\Http\Client\Factory());

    section('Withdrawal payout hash, audit trail and backups');
    $W = $mk('wd', $root0, 10);
    DB::table('users')->where('id', $W->id)->update(['wallet' => '20.00']);
    DB::table('ledger')->insert(['user_id' => $W->id, 'type' => 'refund', 'amount' => '20.00', 'note' => 'test balance', 'created_at' => Carbon::now()]);
    $wid = $wallet->requestWithdrawal(User::find($W->id), 10, 'USDT BEP20', '0x' . str_repeat('9', 40), true);
    $wallet->processWithdrawal($wid, 'approved', '0xPAYOUT123', 'sent');
    $wr = DB::table('withdrawals')->where('id', $wid)->first();
    check('approving stores the payout transaction hash', $wr->status === 'approved' && $wr->payout_txid === '0xPAYOUT123' && $wr->admin_note === 'sent', json_encode($wr));
    $wid2 = $wallet->requestWithdrawal(User::find($W->id), 5, 'USDT BEP20', '0x' . str_repeat('9', 40), true);
    $wallet->processWithdrawal($wid2, 'rejected', '0xIGNORED', 'wrong address');
    $wr2 = DB::table('withdrawals')->where('id', $wid2)->first();
    check('a rejection stores the reason and never a payout hash', $wr2->payout_txid === null && $wr2->admin_note === 'wrong address', json_encode($wr2));

    Audit::log('test.action', $W, 'thing#1', ['amount' => 5, 'password' => 'hunter2', 'nested' => ['pin' => '123456', 'ok' => 'yes']]);
    $a = DB::table('audit_logs')->where('action', 'test.action')->first();
    check('audit entries are written with the actor', $a && str_contains($a->actor, $W->email) && $a->target === 'thing#1', json_encode($a));
    check('passwords / PINs / codes are masked in the audit log', $a && ! str_contains($a->meta, 'hunter2') && ! str_contains($a->meta, '123456') && str_contains($a->meta, '"ok":"yes"'), (string) ($a->meta ?? ''));

    $dir = storage_path('app/backups');
    $before = glob($dir . '/db-*.sql.gz') ?: [];
    Illuminate\Support\Facades\Artisan::call('app:backup', ['--no-files' => true]);
    $after = array_values(array_diff(glob($dir . '/db-*.sql.gz') ?: [], $before));
    check('app:backup writes a gzipped SQL file', count($after) === 1 && filesize($after[0]) > 500, Illuminate\Support\Facades\Artisan::output());
    if ($after) {
        $sql = gzdecode(file_get_contents($after[0]));
        check('the backup contains the tables and the data', str_contains($sql, 'CREATE TABLE') && str_contains($sql, $W->email) && str_contains($sql, 'audit_logs'));
        check('the backup keeps no half-written statements (every line ends in ;)', collect(explode("\n", trim($sql)))->filter(fn ($l) => $l !== '' && ! str_starts_with($l, '--') && ! str_ends_with($l, ';'))->isEmpty());
        @unlink($after[0]);
    }

    section('Ledger integrity (every balance equals the sum of its ledger)');
    $badUsers = [];
    foreach (User::all() as $u) {
        $iw = Money::r(DB::table('ledger')->where('user_id', $u->id)->sum('amount'));
        $pw = Money::r(DB::table('wallet_ledger')->where('user_id', $u->id)->sum('amount'));
        if ($iw !== Money::r($u->wallet)) $badUsers[] = "{$u->code} income $iw vs {$u->wallet}";
        if ($pw !== Money::r($u->purchase_wallet)) $badUsers[] = "{$u->code} purchase $pw vs {$u->purchase_wallet}";
        if (Money::cmp($u->wallet, '0') < 0 || Money::cmp($u->purchase_wallet, '0') < 0) $badUsers[] = "{$u->code} negative balance";
    }
    check('all ' . User::count() . ' accounts balance with their ledgers, none negative', ! $badUsers, implode('; ', array_slice($badUsers, 0, 4)));
    $inv = []; foreach (User::where('role', 'member')->get() as $u) if (Money::r(DB::table('orders')->where('user_id', $u->id)->sum('amount')) !== Money::r($u->invested)) $inv[] = $u->code;
    check('every member\'s invested total equals the sum of their orders', ! $inv, implode(',', array_slice($inv, 0, 5)));
    $capBad = []; foreach (User::where('role', 'member')->get() as $u) { $c = $plan->capInfo($u); if (! $c['unlimited'] && Money::cmp($plan->incomeTotal($u->id), $c['cap']) > 0) $capBad[] = $u->code; }
    check('nobody has earned more than their cap', ! $capBad, implode(',', array_slice($capBad, 0, 5)));

    section('Risk alerts (data errors and suspicious activity)');
    $risk = app(\App\Services\RiskService::class);
    $ids = User::where('role', 'member')->orderBy('id')->take(3)->pluck('id')->all();
    [$ra, $rb, $rc] = $ids;
    $codes = fn (int $id) => array_column($risk->checkMember($id), 'code');
    check('a healthy member has no data-error alerts', ! array_intersect($codes($ra), ['balance-mismatch', 'negative-balance', 'over-cap', 'roi-twice', 'roi-days']), implode(',', $codes($ra)));
    DB::table('users')->where('id', $ra)->update(['wallet' => DB::raw('wallet + 50')]);
    check('income wallet that differs from its ledger is flagged', in_array('balance-mismatch', $codes($ra)));
    DB::table('users')->where('id', $ra)->update(['wallet' => DB::raw('wallet - 50')]);
    DB::table('users')->where('id', $rb)->update(['purchase_wallet' => -5]);
    check('a negative balance is flagged', in_array('negative-balance', $codes($rb)));
    DB::table('users')->where('id', $rb)->update(['purchase_wallet' => 0]);
    $tOrder = DB::table('orders')->where('user_id', $rc)->value('id');
    if ($tOrder) {
        DB::table('ledger')->insert([['user_id' => $rc, 'type' => 'roi', 'amount' => 1, 'note' => 't', 'order_id' => $tOrder, 'created_at' => '2026-01-05 10:00:00'], ['user_id' => $rc, 'type' => 'roi', 'amount' => 1, 'note' => 't', 'order_id' => $tOrder, 'created_at' => '2026-01-05 11:00:00']]);
        check('ROI credited twice for one package on one day is flagged', in_array('roi-twice', $codes($rc)));
        DB::table('ledger')->where('note', 't')->where('user_id', $rc)->delete();
        DB::table('orders')->where('id', $tOrder)->update(['days' => 25, 'paid_days' => 30]);
        check('more ROI days paid than the plan allows is flagged', in_array('roi-days', $codes($rc)));
        DB::table('orders')->where('id', $tOrder)->update(['paid_days' => 0]);
    }
    $big = DB::table('users')->where('id', $ra)->value('invested');
    DB::table('ledger')->insert(['user_id' => $ra, 'type' => 'roi', 'amount' => Money::mul($big ?: 10, 50), 'note' => 'cap-test', 'created_at' => Carbon::now()]);
    check('income above the earning cap is flagged', in_array('over-cap', $codes($ra)));
    DB::table('ledger')->where('note', 'cap-test')->delete();
    DB::table('users')->whereIn('id', [$ra, $rb])->update(['usdt_address' => '0x' . str_repeat('a', 40)]);
    check('the same payout wallet on two members is flagged', in_array('same-wallet', $codes($ra)) && in_array('same-wallet', $codes($rb)));
    DB::table('users')->whereIn('id', [$ra, $rb])->update(['usdt_address' => null]);
    DB::table('users')->whereIn('id', [$ra, $rb, $rc])->update(['last_login_ip' => '8.8.8.8']);
    check('three members on one public IP are flagged', in_array('same-ip', $codes($rb)));
    DB::table('users')->whereIn('id', [$ra, $rb, $rc])->update(['last_login_ip' => '10.0.0.5']);
    check('private / office addresses are not treated as evidence', ! in_array('same-ip', $codes($rb)));
    for ($i = 0; $i < 3; $i++) DB::table('audit_logs')->insert(['actor_id' => $rb, 'actor' => 't', 'action' => 'security.wrong-pin', 'created_at' => Carbon::now()]);
    for ($i = 0; $i < 5; $i++) DB::table('audit_logs')->insert(['actor_id' => $rb, 'actor' => 't', 'action' => 'auth.login-failed', 'created_at' => Carbon::now()]);
    check('repeated wrong PINs and failed sign-ins are flagged', in_array('bad-pins', $codes($rb)) && in_array('bad-logins', $codes($rb)));
    DB::table('deposits')->insert(['user_id' => $rb, 'amount' => 100, 'txn_id' => '0xrisk-test', 'network' => 'USDT', 'status' => 'pending', 'verified' => 'mismatch', 'verify_note' => 'amount differs', 'created_at' => Carbon::now()]);
    check('a deposit that does not match the blockchain is flagged', in_array('deposit-mismatch', $codes($rb)));
    DB::table('withdrawals')->insert(['ref' => 'WRISK1', 'user_id' => $rb, 'amount' => 1500, 'fee' => 150, 'net' => 1350, 'method' => 'USDT', 'account' => '0x1', 'status' => 'pending', 'created_at' => Carbon::now()]);
    DB::table('users')->where('id', $rb)->update(['kyc_status' => 'none']);
    $c2 = $codes($rb);
    check('a large pending withdrawal is flagged', in_array('big-withdrawal', $c2));
    check('a pending withdrawal without approved KYC is flagged', in_array('no-kyc-withdrawal', $c2));
    DB::table('users')->where('id', $rb)->update(['blocked' => true]);
    check('a blocked member with a pending withdrawal is flagged', in_array('blocked-pending', $codes($rb)));
    DB::table('users')->where('id', $rb)->update(['blocked' => false]);
    for ($i = 0; $i < 4; $i++) DB::table('transfers')->insert(['kind' => 'member', 'from_id' => $i % 2 ? $ra : $rc, 'to_id' => $i % 2 ? $rc : $ra, 'amount' => 10, 'fee' => 0.5, 'net' => 9.5, 'created_at' => Carbon::now()]);
    check('money moving back and forth between two members is flagged', in_array('p2p-pingpong', $codes($ra)));

    $r1 = $risk->scan();
    check('a scan raises alerts for what it found', $r1['new'] > 0 && $r1['open'] > 0, json_encode($r1));
    $r2 = $risk->scan();
    check('scanning again does not duplicate alerts', $r2['new'] === 0, json_encode($r2));
    $one = DB::table('risk_alerts')->where('status', 'open')->first();
    DB::table('risk_alerts')->where('id', $one->id)->update(['status' => 'dismissed']);
    $risk->scan();
    check('a dismissed alert is not raised again for the same problem', DB::table('risk_alerts')->where('id', $one->id)->value('status') === 'dismissed');
    DB::table('withdrawals')->where('ref', 'WRISK1')->delete();
    DB::table('deposits')->where('txn_id', '0xrisk-test')->delete();
    $r3 = $risk->scan();
    check('alerts whose cause is gone are cleared automatically', $r3['cleared'] > 0 && DB::table('risk_alerts')->where('code', 'big-withdrawal')->where('status', 'open')->count() === 0, json_encode($r3));
} catch (Throwable $e) {
    $fail++;
    echo "\n  ERROR  " . get_class($e) . ': ' . $e->getMessage() . "\n         at " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    Carbon::setTestNow();
}

echo "\n----------------------------------------\n";
echo "  $pass passed, $fail failed   (database changes rolled back)\n";
exit($fail ? 1 : 0);
