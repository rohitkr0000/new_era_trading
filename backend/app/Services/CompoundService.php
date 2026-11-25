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
 * Every compounding ORDER grows independently (its principal lot is tagged with its order_id). Each trading day
 * the interest is roiPct % × that order's principal — it does NOT auto-route anywhere: it just accumulates in
 * orders.pending_interest, undecided, for as many days as the member leaves it there. Whenever the member likes,
 * decideInterest() lets them act on that order's accumulated pending_interest:
 *   · "transfer": it is paid to the income wallet (capped by the 2X / 5X earning cap) and the bucket resets to 0
 *   · "compound": it is folded into the order's principal lot (the base grows) and the bucket resets to 0
 *       → $100 kept compounding every day: 105, 110.25, 115.76 … after 25 days 338.64
 * On day 25 the order's principal finishes its cycle. Instead of letting its value expire (vanish), the whole
 * order FREEZES ("matured"): its current total (the principal lot, plus any pending_interest never decided on)
 * is parked in orders.matured_balance and it earns nothing further, until the member either withdraws it
 * (withdrawOrder) or starts a brand-new 25-day cycle on it (recompound) — see those two methods below. A member
 * can also withdraw a still-running order early, at the value it has reached so far (its principal lot plus
 * whatever pending_interest had accumulated), forfeiting the rest of that cycle's growth.
 * Saturday and Sunday are not trading days: no interest, and the lot does not age.
 *
 * Pending interest is a balance on paper. It is not realised income until it is paid out — via decideInterest()
 * "transfer", or via withdrawOrder() — so only paid-out interest is trimmed to the earning cap. withdrawOrder()
 * never pays out the principal itself: only the growth above the original purchase amount is credited (as
 * capped "roi").
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
            $lots = DB::table('compound_lots')->where('user_id', $userId)->where('days_left', '>', 0)->orderBy('id')->lockForUpdate()->get()
                ->map(fn ($l) => (array) $l)->all();
            $orderIds = array_values(array_unique(array_filter(array_map(fn ($l) => (int) ($l['order_id'] ?? 0), $lots))));
            $pending = $orderIds ? DB::table('orders')->whereIn('id', $orderIds)->pluck('pending_interest', 'id')->map(fn ($v) => Money::r($v))->all() : [];
            $processed = 0;
            $day = Carbon::parse($u->compound_through, $tz);
            $end = Carbon::parse($today, $tz);

            while ($day->lt($end) && ($maxDays === null || $processed < $maxDays)) {
                $day = $day->copy()->addDay();
                $ds = $day->toDateString();
                if (! Market::isTradingDay($day->copy()->setTime(12, 0))) continue;          // weekend: no interest, the lot does not age

                // group the day's earning lots by the order they belong to — each compounding order grows on its own
                $groups = [];
                foreach ($lots as $l) {
                    if ($l['days_left'] <= 0 || $l['created_on'] >= $ds) continue;   // lots created today start earning tomorrow
                    $groups[(int) ($l['order_id'] ?? 0)][] = $l['id'];
                }
                if (! $groups) continue;

                $totalOpening = '0.00'; $totalInterest = '0.00'; $totalMatured = '0.00';

                foreach ($groups as $groupKey => $lotIds) {
                    $orderId = $groupKey ?: null;
                    $opening = '0.00';
                    foreach ($lots as $l) if (in_array($l['id'], $lotIds, true)) $opening = Money::add($opening, $l['amount']);
                    $interest = Money::pct($opening, $rate);
                    $totalOpening = Money::add($totalOpening, $opening);
                    $totalInterest = Money::add($totalInterest, $interest);

                    // interest never auto-routes: it just piles up, undecided, until the member calls decideInterest()
                    if ($orderId && Money::pos($interest)) $pending[$orderId] = Money::add($pending[$orderId] ?? '0.00', $interest);

                    // age this order's lot; if its principal has just finished its cycle, freeze the whole order
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
                        $maturedTotal = Money::add($maturedTotal, $pending[$orderId] ?? '0.00');   // sweep any undecided interest in — nothing is lost
                        $pending[$orderId] = '0.00';
                        DB::table('orders')->where('id', $orderId)->update(['status' => 'matured', 'matured_balance' => $maturedTotal, 'pending_interest' => '0.00']);
                        $totalMatured = Money::add($totalMatured, $maturedTotal);
                    }
                }

                $closing = array_reduce($lots, fn ($c, $l) => $l['days_left'] > 0 ? Money::add($c, $l['amount']) : $c, '0.00');
                DB::table('compound_ledger')->insertOrIgnore(['user_id' => $userId, 'day' => $ds, 'mode' => 'pending', 'opening' => $totalOpening, 'interest' => $totalInterest, 'expiring' => $totalMatured, 'closing' => $closing]);
                $processed++;
            }
            // persist whatever pending interest is still undecided for orders that did not mature this run
            foreach ($pending as $orderId => $amount) DB::table('orders')->where('id', $orderId)->update(['pending_interest' => $amount]);
            DB::table('users')->where('id', $userId)->update(['compound_through' => $end->toDateString()]);
            Snapshot::forget($userId);
            return $processed;
        });
    }

    /**
     * Member-initiated: decide what happens to one still-running order's accumulated, undecided pending_interest —
     * "transfer" pays it to the income wallet (capped by the earning cap); "compound" folds it into the order's
     * principal lot, so tomorrow's interest is calculated on the bigger base. Either way the bucket resets to 0.
     */
    public function decideInterest(int $userId, int $orderId, string $action): array
    {
        if (! in_array($action, ['transfer', 'compound'], true)) throw new PlanException('Choose "transfer to income wallet" or "keep compounding".');
        return DB::transaction(function () use ($userId, $orderId, $action) {
            $o = DB::table('orders')->where('id', $orderId)->where('user_id', $userId)->lockForUpdate()->first();
            if (! $o || $o->option !== 'Compounding') throw new PlanException('Compounding package not found.');
            if ($o->status !== 'running') throw new PlanException('This package has no pending interest to decide on right now.');

            $amount = Money::r($o->pending_interest);
            if (! Money::pos($amount)) throw new PlanException('No interest has accumulated on this package yet.');

            if ($action === 'transfer') {
                $credited = $this->plan->credit($userId, 'roi', $amount, 'Compounding interest transferred · ' . $o->ref, null, null, $orderId);
                DB::table('orders')->where('id', $orderId)->update(['pending_interest' => '0.00']);
            } else {
                $lot = DB::table('compound_lots')->where('order_id', $orderId)->where('is_principal', 1)->where('days_left', '>', 0)->lockForUpdate()->first();
                if (! $lot) throw new PlanException('This package has no active principal lot to compound into.');
                DB::table('compound_lots')->where('id', $lot->id)->update(['amount' => Money::add($lot->amount, $amount)]);
                DB::table('orders')->where('id', $orderId)->update(['pending_interest' => '0.00']);
                $credited = '0.00';
            }

            Snapshot::forget($userId);
            return ['orderId' => $orderId, 'action' => $action, 'amount' => $amount, 'credited' => $credited];
        });
    }

    /**
     * Member-initiated: cash out one compounding order's growth — early (while it is still running, at whatever
     * it has grown to so far) or its frozen total (once matured). Either way the order closes. The original
     * principal is NEVER paid out to the member (it is forfeited); only the growth above it is "roi" income
     * (capped). Withdrawing before any growth has accrued is refused — it would forfeit the principal for $0.
     */
    public function withdrawOrder(int $userId, int $orderId): array
    {
        return DB::transaction(function () use ($userId, $orderId) {
            $o = DB::table('orders')->where('id', $orderId)->where('user_id', $userId)->lockForUpdate()->first();
            if (! $o || $o->option !== 'Compounding') throw new PlanException('Compounding package not found.');
            if ($o->status === 'closed') throw new PlanException('This package has already been withdrawn.');

            if ($o->status === 'matured') {
                $value = $o->matured_balance;   // pending_interest was already swept into matured_balance at maturity time
            } else {
                $value = Money::add(DB::table('compound_lots')->where('order_id', $orderId)->where('days_left', '>', 0)->sum('amount') ?: '0', $o->pending_interest);
                DB::table('compound_lots')->where('order_id', $orderId)->where('days_left', '>', 0)->update(['days_left' => 0]);
            }

            $growth = Money::cmp($value, $o->amount) > 0 ? Money::sub($value, $o->amount) : '0.00';
            if (! Money::pos($growth)) throw new PlanException('Nothing to withdraw yet — only the growth above your original ' . Money::r($o->amount) . ' investment can be withdrawn; the principal itself is not paid out.');

            DB::table('orders')->where('id', $orderId)->update(['status' => 'closed', 'matured_balance' => null, 'pending_interest' => '0.00']);

            $credited = $this->plan->credit($userId, 'roi', $growth, 'Compounding growth withdrawn · ' . $o->ref, null, null, $orderId);

            Snapshot::forget($userId);
            return ['orderId' => $orderId, 'value' => $value, 'growthCredited' => $credited];
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
            DB::table('orders')->where('id', $orderId)->update(['status' => 'running', 'matured_balance' => null, 'pending_interest' => '0.00', 'pct' => $s['roiPct'], 'days' => $cycle, 'paid_days' => 0]);

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
