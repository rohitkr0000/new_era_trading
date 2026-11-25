<?php

namespace App\Services;

use App\Models\User;
use App\Support\Money;
use App\Support\PlanException;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Automatic compounding — the company spreadsheet ("Compounding Calculator" and its three "after 25 / 50 days" tabs).
 *
 * Every compounding ORDER grows independently (its lots are tagged with its order_id):
 *   · the purchased amount itself (principal lot): earns on days 1…25 after the purchase day
 *   · an interest lot created on day d:             earns on days d+1…d+25, tagged to the same order
 * Each trading day the interest is  roiPct % × (that order's lots that are live at the start of the day):
 *   · mode "compound": the interest becomes a NEW lot (the base grows)             → $100: 105, 110.25, 115.76 … after 25 days 338.64
 *   · mode "cash":     the interest is paid to the income wallet (and counts against the 2X / 5X earning cap)
 * On day 25 the order's principal finishes its cycle. Instead of letting its value expire (vanish), the whole
 * order FREEZES ("matured"): its current total (principal + every interest lot it ever spawned) is parked in
 * orders.matured_balance and it earns nothing further, until the member either withdraws it (withdrawOrder) or
 * starts a brand-new 25-day cycle on it (recompound) — see those two methods below. A member can also withdraw
 * a still-running order early, at the value it has reached so far (forfeiting the rest of that cycle's growth).
 * Saturday and Sunday are not trading days: no interest, and the lots do not age.
 *
 * Compounded interest is a balance on paper. It is not realised income until it is paid out — in cash mode daily,
 * or via withdrawOrder() — so only paid-out interest is trimmed to the earning cap; the principal portion of a
 * withdrawal is a refund of the member's own money, never capped.
 *
 * The job is idempotent (compound_ledger is unique per user and day) and catches up on days a stopped server missed.
 */
class CompoundService
{
    public function __construct(private PlanEngine $plan) {}

    private function tz(): string { return Settings::get('timezone'); }

    public function today(?Carbon $now = null): string
    {
        return ($now ?? Carbon::now())->copy()->setTimezone($this->tz())->toDateString();
    }

    /** Called when a compounding package is bought. */
    public function openLot(int $userId, $amount, ?int $orderId): void
    {
        $s = Settings::all();
        $cycle = max(1, (int) ($s['roiDays'] ?: 25));
        $today = $this->today();
        DB::table('compound_lots')->insert([
            'user_id' => $userId, 'order_id' => $orderId, 'amount' => Money::r($amount), 'is_principal' => true,
            'created_on' => $today, 'days_left' => $cycle,
        ]);
        // days before the first lot never existed: start counting from the purchase day
        DB::table('users')->where('id', $userId)->whereNull('compound_through')->update(['compound_through' => $today]);
    }

    /** Live balance of the member's compounding lots, plus any matured orders waiting on a withdraw/re-compound decision. */
    public function balance(int $userId): string
    {
        $live = DB::table('compound_lots')->where('user_id', $userId)->where('days_left', '>', 0)->sum('amount');
        $matured = DB::table('orders')->where('user_id', $userId)->where('option', 'Compounding')->where('status', 'matured')->sum('matured_balance');
        return Money::r(Money::add($live ?: '0', $matured ?: '0'));
    }

    public function setMode(User $u, string $mode): void
    {
        if (! in_array($mode, ['compound', 'cash'], true)) throw new PlanException('Choose "keep compounding" or "withdraw the daily interest".');
        DB::table('users')->where('id', $u->id)->update(['compound_mode' => $mode]);
    }

    /** Daily job for everybody who still has live lots. @return int number of member-days processed */
    public function accrue(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $today = $this->today($now);
        $done = 0;
        $ids = DB::table('users')->where('role', 'member')->whereNotNull('compound_through')->where('compound_through', '<', $today)
            ->whereIn('id', DB::table('compound_lots')->where('days_left', '>', 0)->select('user_id'))->pluck('id');
        foreach ($ids as $id) $done += $this->accrueUser((int) $id, $today);
        return $done;
    }

    /** Process every missing day up to $today for one member (or, with $maxDays, stop after that many trading days — used by fastForward()). */
    public function accrueUser(int $userId, string $today, ?int $maxDays = null): int
    {
        return DB::transaction(function () use ($userId, $today, $maxDays) {
            $u = User::lockForUpdate()->find($userId);
            if (! $u || ! $this->plan->isActive($u) || ! $u->compound_through) return 0;

            $s = Settings::all();
            $tz = $s['timezone'];
            $rate = $s['roiPct'];
            $cycle = max(1, (int) ($s['roiDays'] ?: 25));
            $mode = $u->compound_mode === 'cash' ? 'cash' : 'compound';
            $lots = DB::table('compound_lots')->where('user_id', $userId)->where('days_left', '>', 0)->orderBy('id')->lockForUpdate()->get()
                ->map(fn ($l) => (array) $l)->all();
            $processed = 0;
            $day = Carbon::parse($u->compound_through, $tz);
            $end = Carbon::parse($today, $tz);

            while ($day->lt($end) && ($maxDays === null || $processed < $maxDays)) {
                $day = $day->copy()->addDay();
                $ds = $day->toDateString();
                if (! Market::isTradingDay($day->copy()->setTime(12, 0))) continue;          // weekend: no interest, the lots do not age

                // group the day's earning lots by the order they belong to — each compounding order grows on its own
                $groups = [];
                foreach ($lots as $l) {
                    if ($l['days_left'] <= 0 || $l['created_on'] >= $ds) continue;   // lots created today start earning tomorrow
                    $groups[(int) ($l['order_id'] ?? 0)][] = $l['id'];
                }
                if (! $groups) continue;

                $at = $day->copy()->setTime(0, 1)->setTimezone('UTC');
                $totalOpening = '0.00'; $totalInterest = '0.00'; $totalMatured = '0.00';

                foreach ($groups as $groupKey => $lotIds) {
                    $orderId = $groupKey ?: null;
                    $opening = '0.00';
                    foreach ($lots as $l) if (in_array($l['id'], $lotIds, true)) $opening = Money::add($opening, $l['amount']);
                    $interest = Money::pct($opening, $rate);
                    $totalOpening = Money::add($totalOpening, $opening);
                    $totalInterest = Money::add($totalInterest, $interest);

                    if ($mode === 'cash') {
                        $this->plan->credit($userId, 'roi', $interest, 'ROI ' . (float) $rate . '% on compounding balance ' . Money::r($opening) . ' · ' . $ds, null, $at, $orderId);
                    } elseif (Money::pos($interest)) {
                        $id = DB::table('compound_lots')->insertGetId(['user_id' => $userId, 'order_id' => $orderId, 'amount' => $interest, 'is_principal' => false, 'created_on' => $ds, 'days_left' => $cycle]);
                        $lots[] = ['id' => $id, 'user_id' => $userId, 'order_id' => $orderId, 'amount' => $interest, 'is_principal' => 0, 'created_on' => $ds, 'days_left' => $cycle];
                    }

                    // age this order's lots; if its principal has just finished its cycle, freeze the whole order
                    $maturedNow = false;
                    foreach ($lots as &$l) {
                        if ((int) ($l['order_id'] ?? 0) !== $groupKey || $l['days_left'] <= 0 || $l['created_on'] >= $ds) continue;
                        $l['days_left']--;
                        DB::table('compound_lots')->where('id', $l['id'])->update(['days_left' => $l['days_left']]);
                        if ($l['is_principal']) {
                            DB::table('orders')->where('id', $orderId)->increment('paid_days');
                            if ($l['days_left'] === 0) $maturedNow = true;
                        }
                    }
                    unset($l);

                    if ($maturedNow && $orderId) {
                        $maturedTotal = '0.00';
                        foreach ($lots as &$l) {
                            if ((int) ($l['order_id'] ?? 0) !== $groupKey) continue;
                            $maturedTotal = Money::add($maturedTotal, $l['amount']);
                            $l['days_left'] = 0;
                            DB::table('compound_lots')->where('id', $l['id'])->update(['days_left' => 0]);
                        }
                        unset($l);
                        DB::table('orders')->where('id', $orderId)->update(['status' => 'matured', 'matured_balance' => $maturedTotal]);
                        $totalMatured = Money::add($totalMatured, $maturedTotal);
                    }
                }

                $closing = array_reduce($lots, fn ($c, $l) => $l['days_left'] > 0 ? Money::add($c, $l['amount']) : $c, '0.00');
                DB::table('compound_ledger')->insertOrIgnore(['user_id' => $userId, 'day' => $ds, 'mode' => $mode, 'opening' => $totalOpening, 'interest' => $totalInterest, 'expiring' => $totalMatured, 'closing' => $closing]);
                $processed++;
            }
            DB::table('users')->where('id', $userId)->update(['compound_through' => $end->toDateString()]);
            Snapshot::forget($userId);
            return $processed;
        });
    }

    /**
     * Member-initiated: cash out one compounding order's current value — early (while it is still running, at
     * whatever it has grown to so far) or its frozen total (once matured). Either way the order closes. The
     * original principal is refunded in full (never capped); any growth beyond it is "roi" income (capped).
     */
    public function withdrawOrder(int $userId, int $orderId): array
    {
        return DB::transaction(function () use ($userId, $orderId) {
            $o = DB::table('orders')->where('id', $orderId)->where('user_id', $userId)->lockForUpdate()->first();
            if (! $o || $o->option !== 'Compounding') throw new PlanException('Compounding package not found.');
            if ($o->status === 'closed') throw new PlanException('This package has already been withdrawn.');

            if ($o->status === 'matured') {
                $value = $o->matured_balance;
            } else {
                $value = Money::r(DB::table('compound_lots')->where('order_id', $orderId)->where('days_left', '>', 0)->sum('amount'));
                DB::table('compound_lots')->where('order_id', $orderId)->where('days_left', '>', 0)->update(['days_left' => 0]);
            }
            if (! Money::pos($value)) throw new PlanException('Nothing to withdraw on this package yet.');

            DB::table('orders')->where('id', $orderId)->update(['status' => 'closed', 'matured_balance' => null]);

            $principal = Money::cmp($value, $o->amount) > 0 ? $o->amount : $value;
            $growth = Money::cmp($value, $o->amount) > 0 ? Money::sub($value, $o->amount) : '0.00';
            $refunded = Money::pos($principal) ? $this->plan->credit($userId, 'refund', $principal, 'Compounding principal returned · ' . $o->ref, null, null, $orderId) : '0.00';
            $credited = Money::pos($growth) ? $this->plan->credit($userId, 'roi', $growth, 'Compounding growth withdrawn · ' . $o->ref, null, null, $orderId) : '0.00';

            Snapshot::forget($userId);
            return ['orderId' => $orderId, 'value' => $value, 'principalReturned' => $refunded, 'growthCredited' => $credited];
        });
    }

    /** Member-initiated: start a brand-new 25-day cycle on a matured package, using its frozen total as the new principal. */
    public function recompound(int $userId, int $orderId): array
    {
        return DB::transaction(function () use ($userId, $orderId) {
            $o = DB::table('orders')->where('id', $orderId)->where('user_id', $userId)->lockForUpdate()->first();
            if (! $o || $o->option !== 'Compounding') throw new PlanException('Compounding package not found.');
            if ($o->status !== 'matured') throw new PlanException('This package is not ready to re-compound yet — it must finish its current cycle first.');

            $s = Settings::all();
            $cycle = max(1, (int) ($s['roiDays'] ?: 25));
            $value = $o->matured_balance;
            if (! Money::pos($value)) throw new PlanException('Nothing to re-compound.');

            // orders.amount stays the ORIGINAL purchase amount for the order's lifetime (even across several re-compound
            // cycles) — it is the permanent baseline withdrawOrder() uses to tell principal (uncapped) from growth
            // (capped); overwriting it here would let growth rolled into a cycle quietly escape the cap forever.
            $today = $this->today();
            DB::table('compound_lots')->insert(['user_id' => $userId, 'order_id' => $orderId, 'amount' => $value, 'is_principal' => true, 'created_on' => $today, 'days_left' => $cycle]);
            DB::table('orders')->where('id', $orderId)->update(['status' => 'running', 'matured_balance' => null, 'pct' => $s['roiPct'], 'days' => $cycle, 'paid_days' => 0]);

            Snapshot::forget($userId);
            return ['orderId' => $orderId, 'principal' => $value, 'through' => $today];
        });
    }

    /**
     * TEST TOOL (admin panel only): instantly process N more trading days of compounding for one member, across
     * all of their compounding orders at once (each still grows/matures on its own — see accrueUser() above).
     */
    public function fastForward(int $userId, int $days): array
    {
        $days = max(1, min(400, (int) $days));
        $u = User::find($userId);
        if (! $u || ! $u->compound_through) throw new PlanException('This member has no compounding package.');
        $farFuture = Carbon::parse($u->compound_through)->addDays($days * 3 + 30)->toDateString();   // a bound the day-count never actually reaches
        $processed = $this->accrueUser($userId, $farFuture, $days);
        if ($processed === 0) throw new PlanException('Nothing to advance — the member may be blocked, have no investment, or all lots already expired.');
        return ['userId' => $userId, 'processedDays' => $processed, 'balance' => $this->balance($userId), 'through' => DB::table('users')->where('id', $userId)->value('compound_through')];
    }
}
