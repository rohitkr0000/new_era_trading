<?php

namespace App\Services;

use App\Models\User;
use App\Support\Money;
use App\Support\Phone;
use App\Support\PlanException;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The New Era Trading compensation engine.
 *
 *  · ROI           — roiPct % of each package per trading day (Mon–Fri) for roiDays (25) days, run by `php artisan net:accrue`.
 *                    Simple interest: paid to the income wallet. Compounding: added to a compounding balance (see CompoundService).
 *  · Direct income — directPct % of a direct member's joining package → sponsor
 *  · Retopup       — retopupPct % of a direct member's later packages → sponsor
 *  · Level income  — levelPct[k-1] % of EVERY investment (joining + retopup) made by a member k levels below, k = 1…12
 *                    (L1 2%, L2 1%, L3–L12 0.2%). Level k is unlocked only when the receiver has ≥ k active direct referrals.
 *  · Earning cap   — capNonWorking (2X) × invested; capWorking (5X) once the member's level-1 business (the money their direct
 *                    referrals have invested) reaches capBusinessMultiple (5) × their own investment  [capMode = 'business']
 *  · Amounts       — joining / retopup in multiples of joinMultiple ($5)
 *
 * Every balance change happens inside a DB transaction with row locks and is written to a ledger.
 */
class PlanEngine
{
    public const INCOME_TYPES = ['roi', 'direct', 'retopup', 'level'];

    /* ------------------------------------------------------------------ helpers */

    public function isActive(?User $u): bool
    {
        return $u && $u->role === 'member' && ! $u->blocked && Money::pos($u->invested);
    }

    public function activeDirects(int $userId): int
    {
        return User::where('sponsor_id', $userId)->where('role', 'member')->where('blocked', false)->where('invested', '>', 0)->count();
    }

    /** Level-1 business: what the member's active direct referrals have invested in total. */
    public function directBusiness(int $userId): string
    {
        return Money::r(User::where('sponsor_id', $userId)->where('role', 'member')->where('blocked', false)->where('invested', '>', 0)->sum('invested'));
    }

    /**
     * "Working" unlocks the 5X cap. Default rule (guide, terms #4): the level-1 business is at least capBusinessMultiple (5) times
     * the member's own investment. Alternative (capMode = 'directs'): at least capDirectsRequired active direct referrals.
     */
    public function isWorking(int $userId): bool
    {
        $s = Settings::all();
        if (($s['capMode'] ?? 'business') === 'directs') return $this->activeDirects($userId) >= (int) $s['capDirectsRequired'];
        $invested = User::where('id', $userId)->value('invested');
        if (! Money::pos($invested)) return false;
        return Money::cmp($this->directBusiness($userId), Money::mul($invested, $s['capBusinessMultiple'] ?? 5)) >= 0;
    }

    public function incomeTotal(int $userId): string
    {
        return Money::r(DB::table('ledger')->where('user_id', $userId)->whereIn('type', self::INCOME_TYPES)->sum('amount'));
    }

    /** @return array{cap:string, used:string, left:string, pct:float, mult:float, working:bool, unlimited:bool} */
    public function capInfo(User $u): array
    {
        $s = Settings::all();
        $directs = $this->activeDirects($u->id);
        $working = $this->isWorking($u->id);
        $mult = (float) ($working ? $s['capWorking'] : $s['capNonWorking']);
        $used = $this->incomeTotal($u->id);
        $biz = $this->directBusiness($u->id);
        $need = Money::mul($u->invested, $s['capBusinessMultiple'] ?? 5);
        $extra = ['directs' => $directs, 'directBusiness' => $biz, 'businessNeeded' => $need, 'mode' => $s['capMode'] ?? 'business'];
        if ($mult <= 0) return ['cap' => '0.00', 'used' => $used, 'left' => '0.00', 'pct' => 0, 'mult' => $mult, 'working' => $working, 'unlimited' => true] + $extra;
        $cap = Money::mul($u->invested, $mult);
        $left = Money::cmp($cap, $used) > 0 ? Money::sub($cap, $used) : '0.00';
        $pct = Money::pos($cap) ? min(100, round(Money::f($used) / Money::f($cap) * 100, 2)) : 0;
        return ['cap' => $cap, 'used' => $used, 'left' => $left, 'pct' => $pct, 'mult' => $mult, 'working' => $working, 'unlimited' => false] + $extra;
    }

    /** Credit the income wallet (income types are trimmed to the earning cap). Returns the amount actually credited. */
    public function credit(int $userId, string $type, $amount, string $note, ?int $fromId = null, ?CarbonInterface $at = null, ?int $orderId = null): string
    {
        $amount = Money::r($amount);
        if (! Money::pos($amount)) return '0.00';
        $u = User::lockForUpdate()->findOrFail($userId);
        if (in_array($type, self::INCOME_TYPES, true)) {
            $c = $this->capInfo($u);
            if (! $c['unlimited']) {
                if (! Money::pos($c['left'])) return '0.00';
                if (Money::cmp($amount, $c['left']) > 0) $amount = $c['left'];
            }
        }
        $u->wallet = Money::add($u->wallet, $amount);
        $u->save();
        Snapshot::forget($userId);
        DB::table('ledger')->insert([
            'user_id' => $userId, 'type' => $type, 'amount' => $amount, 'note' => $note,
            'from_id' => $fromId, 'order_id' => $orderId, 'created_at' => $at ?? Carbon::now(),
        ]);
        return $amount;
    }

    public function debit(User $u, string $type, $amount, string $note): void
    {
        $u->wallet = Money::sub($u->wallet, $amount);
        $u->save();
        Snapshot::forget($u->id);
        DB::table('ledger')->insert(['user_id' => $u->id, 'type' => $type, 'amount' => Money::sub('0', $amount), 'note' => $note, 'created_at' => Carbon::now()]);
    }

    /** Purchase-wallet movement (amount may be negative). */
    public function walletMove(User $u, string $type, $amount, string $note): void
    {
        $u->purchase_wallet = Money::add($u->purchase_wallet, $amount);
        $u->save();
        Snapshot::forget($u->id);
        DB::table('wallet_ledger')->insert(['user_id' => $u->id, 'type' => $type, 'amount' => Money::r($amount), 'balance' => $u->purchase_wallet, 'note' => $note, 'created_at' => Carbon::now()]);
    }

    private function inBinarySubtree(int $rootId, int $id): bool
    {
        $cur = User::find($id);
        while ($cur) {
            if ($cur->id === $rootId) return true;
            $cur = $cur->parent_id ? User::find($cur->parent_id) : null;
        }
        return false;
    }

    /** Joining / retopup amounts: at least minJoin and a multiple of joinMultiple ($5, $10, $15 …). */
    public function checkAmount($amount, string $label = 'amount'): void
    {
        $s = Settings::all();
        if (Money::cmp($amount, $s['minJoin']) < 0) throw new PlanException('Minimum ' . $label . ' is ' . $s['currency'] . (float) $s['minJoin'] . '.');
        $m = Money::r($s['joinMultiple']);
        if (Money::pos($m) && bccomp(bcmod(Money::r($amount), $m, 2), '0', 2) !== 0) {
            throw new PlanException(ucfirst($label) . ' must be in multiples of ' . $s['currency'] . (float) $m . ' (5, 10, 15, 20…).');
        }
    }

    /* ------------------------------------------------------------------ registration */

    /**
     * @param array{name:string,email:string,password:string,phone?:string,sponsorCode?:string,leg?:string,parentId?:int|string,amount?:float|string} $d
     */
    public function register(array $d, bool $root = false): User
    {
        $s = Settings::all();
        $amount = isset($d['amount']) && $d['amount'] !== '' && $d['amount'] !== null ? Money::r($d['amount']) : null;
        if ($amount !== null) $this->checkAmount($amount, 'joining amount');

        $email = strtolower(trim($d['email'] ?? ''));
        $phone = trim($d['phone'] ?? '');
        if (! preg_match('/^\d{10}$/', $phone)) throw new PlanException('Enter a valid 10-digit mobile number (digits only).');
        $phoneKey = Phone::key($phone);
        if ($phoneKey === null) throw new PlanException('Enter a valid 10-digit mobile number (digits only).');
        if (User::where('email', $email)->exists()) throw new PlanException('This email is already registered.');
        if (User::where('phone_key', $phoneKey)->exists()) throw new PlanException('This mobile number is already registered.');

        try {
            return $this->createMember($d, $root, $amount, $email, $phone, $phoneKey);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {   // two sign-ups racing for the same email / mobile
            throw new PlanException('This email or mobile number is already registered.');
        }
    }

    /** A random, unique, non-sequential 6-digit member ID (no prefix, so it gives away neither join order nor member count). */
    private static function generateCode(): string
    {
        do {
            $code = (string) random_int(100000, 999999);
        } while (User::where('code', $code)->exists());
        return $code;
    }

    private function createMember(array $d, bool $root, ?string $amount, string $email, string $phone, string $phoneKey): User
    {
        return DB::transaction(function () use ($d, $root, $amount, $email, $phone, $phoneKey) {
            $sponsor = null;
            if (! $root) {
                $sponsor = User::where('code', strtoupper(trim($d['sponsorCode'] ?? '')))->where('role', 'member')->lockForUpdate()->first();
                if (! $sponsor) throw new PlanException('Invalid sponsor code.');
            }

            $u = User::create([
                'name' => trim($d['name']), 'email' => $email, 'password' => $d['password'],
                'phone' => $phone, 'phone_key' => $phoneKey, 'role' => 'member', 'joined_at' => Carbon::now(),
                'txn_pin' => ! empty($d['pin']) ? Hash::make($d['pin']) : null,   // set at registration (the register form now requires it)
            ]);
            $u->code = self::generateCode();

            if ($sponsor) {
                $leg = ($d['leg'] ?? 'left') === 'right' ? 'right' : 'left';
                $col = $leg . '_id';
                $parent = null;
                if (! empty($d['parentId'])) {
                    $wanted = User::lockForUpdate()->find((int) $d['parentId']);
                    if ($wanted && ! $wanted->$col && $this->inBinarySubtree($sponsor->id, $wanted->id)) $parent = $wanted;
                }
                if (! $parent) {                                    // extreme-leg auto placement
                    $parent = $sponsor;
                    while ($parent->$col) $parent = User::lockForUpdate()->find($parent->$col);
                }
                $parent->$col = $u->id;
                $parent->save();
                $u->sponsor_id = $sponsor->id;
                $u->parent_id = $parent->id;
                $u->leg = $leg;
            }
            $u->save();

            if ($amount !== null) $this->invest($u->id, $amount);
            return $u->fresh();
        });
    }

    /* ------------------------------------------------------------------ packages */

    /** Joining / retopup package + direct or retopup income to the sponsor. */
    public function invest(int $userId, $amount, string $option = 'Investment'): array
    {
        $s = Settings::all();
        $amount = Money::r($amount);
        $this->checkAmount($amount, 'package amount');

        return DB::transaction(function () use ($userId, $amount, $option, $s) {
            $u = User::lockForUpdate()->findOrFail($userId);
            $retopup = Money::pos($u->invested);
            $u->invested = Money::add($u->invested, $amount);
            $u->save();

            $now = Carbon::now();
            $orderId = DB::table('orders')->insertGetId([
                'ref' => 'TMP-' . uniqid(), 'user_id' => $u->id, 'amount' => $amount, 'kind' => $retopup ? 'Retopup' : 'Joining',
                'option' => $option, 'pct' => $s['roiPct'], 'days' => (int) $s['roiDays'], 'steps' => 0, 'paid_days' => 0,
                'status' => $s['roiPct'] > 0 ? 'running' : 'active', 'created_at' => $now,
            ]);
            DB::table('orders')->where('id', $orderId)->update(['ref' => ($option === 'Compounding' ? 'CMP-' : 'ROI-') . (1000 + $orderId)]);
            // compounding package: the amount becomes a lot that earns (and compounds) for the cycle — see CompoundService
            if ($option === 'Compounding' && $s['roiPct'] > 0) app(CompoundService::class)->openLot($u->id, $amount, $orderId);

            if ($u->sponsor_id) {
                $sp = User::find($u->sponsor_id);
                if ($this->isActive($sp)) {
                    $label = ' #' . $u->code . ' (' . $s['currency'] . $amount . ')';
                    $retopup
                        ? $this->credit($sp->id, 'retopup', Money::pct($amount, $s['retopupPct']), 'Retopup Income' . $label, $u->id)
                        : $this->credit($sp->id, 'direct', Money::pct($amount, $s['directPct']), 'Direct Referral Income' . $label, $u->id);
                }
            }

            // 12-level income on this investment (joining AND retopup): level k pays levelPct[k-1] %,
            // unlocked only when the receiver has ≥ k active direct referrals (12 directs → all 12 levels)
            $cur = $u;
            foreach ($s['levelPct'] as $i => $pct) {
                if (! $cur->sponsor_id) break;
                $cur = User::find($cur->sponsor_id);
                $lvl = $i + 1;
                if ($pct <= 0 || ! $this->isActive($cur)) continue;
                if ($s['levelUnlockPerLevel'] && $this->activeDirects($cur->id) < $lvl) continue;
                $this->credit($cur->id, 'level', Money::pct($amount, $pct), 'Level ' . $lvl . ' income #' . $u->code . ' (' . $s['currency'] . $amount . ')', $u->id, null, $orderId);
            }

            // team business per binary leg (shown in the genealogy; no binary payout in this plan)
            $child = $u;
            $anc = $u->parent_id ? User::lockForUpdate()->find($u->parent_id) : null;
            while ($anc) {
                $col = $child->leg === 'left' ? 'left_bv' : 'right_bv';
                $anc->$col = Money::add($anc->$col, $amount);
                $anc->save();
                $child = $anc;
                $anc = $anc->parent_id ? User::lockForUpdate()->find($anc->parent_id) : null;
            }
            return ['amount' => $amount, 'retopup' => $retopup, 'orderId' => $orderId];
        });
    }

    /**
     * Member buys a package from the purchase wallet or the income wallet (re-invest).
     * $mode: "simple" = the daily 5% is paid to the wallet · "compound" = the daily 5% is added to the compounding balance.
     */
    public function purchase(User $member, $amount, string $source, string $mode = 'simple'): array
    {
        if (! in_array($mode, ['simple', 'compound'], true)) throw new PlanException('Choose simple interest or compounding.');
        $s = Settings::all();
        $amount = Money::r($amount);
        $this->checkAmount($amount, 'package amount');

        return DB::transaction(function () use ($member, $amount, $source, $mode, $s) {
            $u = User::lockForUpdate()->findOrFail($member->id);
            if ($source === 'income') {
                if (Money::cmp($u->wallet, $amount) < 0) throw new PlanException('Insufficient income wallet balance.');
                $this->debit($u, 'purchase', $amount, 'Package purchase (re-invest from income wallet)');
            } else {
                if (Money::cmp($u->purchase_wallet, $amount) < 0) throw new PlanException('Insufficient purchase wallet balance — deposit USDT first.');
                $this->walletMove($u, 'purchase', Money::sub('0', $amount), (Money::pos($u->invested) ? 'Retopup' : 'Joining') . ' package ' . $s['currency'] . $amount);
            }
            return $this->invest($u->id, $amount, $mode === 'compound' ? 'Compounding' : 'Investment');
        });
    }

    /* ------------------------------------------------------------------ daily ROI */

    /**
     * Credits every ROI calendar day that is due (only trading days pay), back-dated to the day it was earned.
     * Every member is credited at the same wall-clock moment (just after midnight, company timezone) regardless
     * of what time of day they bought — a package bought at 11 PM and one bought at 1 AM both earn their first
     * day at the next midnight, same as CompoundService's compounding packages.
     * Level income is NOT paid on ROI — it is paid on investments (see invest()). Idempotent — safe to run as often as you like.
     */
    public function accrue(): int
    {
        $s = Settings::all();
        $tz = $s['timezone'];
        $now = Carbon::now();
        $today = $now->copy()->setTimezone($tz)->startOfDay();
        $credits = 0;

        // simple-interest packages only; compounding packages are run by CompoundService below
        foreach (DB::table('orders')->where('status', 'running')->where('option', '!=', 'Compounding')->orderBy('id')->pluck('id') as $orderId) {
            DB::transaction(function () use ($orderId, $tz, $today, &$credits) {
                $o = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
                $purchaseDay = Carbon::parse($o->created_at)->setTimezone($tz)->startOfDay();
                $day = $purchaseDay->copy()->addDays($o->steps);   // last calendar day already processed
                if (! $day->lt($today)) return;

                $steps = $o->steps;
                $paid = $o->paid_days;
                while ($day->lt($today)) {
                    $day = $day->copy()->addDay();
                    $steps++;
                    if (! Market::isTradingDay($day->copy()->setTime(12, 0))) continue;   // weekend: trading off, the day does not age into a paid day
                    if ($o->days && $paid >= $o->days) break;
                    $paid++;
                    $u = User::find($o->user_id);
                    if (! $this->isActive($u)) continue;

                    $when = $day->copy()->setTime(0, 1)->setTimezone('UTC');   // just after midnight, company timezone — same instant for everyone
                    $roi = $this->credit($u->id, 'roi', Money::pct($o->amount, $o->pct), 'ROI ' . (float) $o->pct . '% on ' . $o->ref . ' · trading day ' . $paid, null, $when, $o->id);
                    if (Money::pos($roi)) $credits++;
                }
                $status = ($o->days && $paid >= $o->days) ? 'closed' : $o->status;
                DB::table('orders')->where('id', $orderId)->update(['steps' => $steps, 'paid_days' => $paid, 'status' => $status]);
            });
        }
        return $credits + app(CompoundService::class)->accrue($now);
    }

    /**
     * TEST TOOL (admin panel only): instantly credit N more trading days of ROI for ONE simple-interest
     * package, instead of waiting for `net:accrue` to catch up with real time. Compounding packages are not
     * per-package (all of a member's lots are processed together) — see CompoundService::fastForward() for those.
     */
    public function fastForwardOrder(int $orderId, int $days): array
    {
        $days = max(1, min(400, (int) $days));
        return DB::transaction(function () use ($orderId, $days) {
            $o = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $o) throw new PlanException('Package not found.');
            if ($o->option === 'Compounding') throw new PlanException('This is a compounding package — use the compounding fast-forward instead.');
            if ($o->status !== 'running') throw new PlanException('This package is not running (status: ' . $o->status . ').');

            $tz = Settings::get('timezone');
            $day = Carbon::parse($o->created_at)->setTimezone($tz)->startOfDay()->addDays($o->steps);
            $steps = $o->steps;
            $paid = $o->paid_days;
            $target = $paid + $days;
            $credited = 0;

            while ($paid < $target) {
                if ($o->days && $paid >= $o->days) break;
                $day = $day->copy()->addDay();
                $steps++;
                if (! Market::isTradingDay($day->copy()->setTime(12, 0))) continue;
                $paid++;
                $u = User::find($o->user_id);
                if (! $this->isActive($u)) continue;

                $when = $day->copy()->setTime(0, 1)->setTimezone('UTC');
                $roi = $this->credit($u->id, 'roi', Money::pct($o->amount, $o->pct), 'ROI ' . (float) $o->pct . '% on ' . $o->ref . ' · trading day ' . $paid . ' (admin test fast-forward)', null, $when, $o->id);
                if (Money::pos($roi)) $credited++;
            }
            $status = ($o->days && $paid >= $o->days) ? 'closed' : $o->status;
            DB::table('orders')->where('id', $orderId)->update(['steps' => $steps, 'paid_days' => $paid, 'status' => $status]);
            return ['orderId' => $orderId, 'paidDays' => $paid, 'days' => (int) $o->days, 'credited' => $credited, 'status' => $status];
        });
    }
}
