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
 * A LOT is an amount that earns roiPct % per trading day for roiDays (25) trading days and then expires:
 *   · the purchased amount itself (principal lot): earns on days 1…25 after the purchase day
 *   · an interest lot created on day d:             earns on days d+1…d+25
 * Each trading day the interest is  roiPct % × (every lot that is live at the start of that day):
 *   · mode "compound": the interest becomes a NEW lot (the base grows)             → $100: 105, 110.25, 115.76 … after 25 days 338.64
 *   · mode "cash":     the interest is paid to the income wallet (and counts against the 2X / 5X earning cap)
 * and then every lot that has just earned its last day expires (its value leaves the balance):
 *   · day 25: the $100 principal expires → 338.64 − 100 = 238.64 remains
 *   · day 26: interest 5% × 238.64 = 11.93, the lot from day 1 ($5.00) expires → 245.57 (compounding)
 * Saturday and Sunday are not trading days: no interest, and the lots do not age.
 *
 * Compounded interest is a balance on paper. It is not income until it is paid out in cash mode, so only cash
 * interest is trimmed to the earning cap and can be withdrawn.
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

    /** Live balance of the member's compounding lots. */
    public function balance(int $userId): string
    {
        return Money::r(DB::table('compound_lots')->where('user_id', $userId)->where('days_left', '>', 0)->sum('amount'));
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

    /** Process every missing day up to $today for one member. */
    public function accrueUser(int $userId, string $today): int
    {
        return DB::transaction(function () use ($userId, $today) {
            $u = User::lockForUpdate()->find($userId);
            if (! $u || ! $this->plan->isActive($u) || ! $u->compound_through) return 0;

            $s = Settings::all();
            $tz = $s['timezone'];
            $rate = $s['roiPct'];
            $lots = DB::table('compound_lots')->where('user_id', $userId)->where('days_left', '>', 0)->orderBy('id')->lockForUpdate()->get()
                ->map(fn ($l) => (array) $l)->all();
            $processed = 0;
            $day = Carbon::parse($u->compound_through, $tz);
            $end = Carbon::parse($today, $tz);

            while ($day->lt($end)) {
                $day = $day->copy()->addDay();
                $ds = $day->toDateString();
                if (! Market::isTradingDay($day->copy()->setTime(12, 0))) continue;          // weekend: no interest, the lots do not age

                // lots created on this very day start earning tomorrow
                $earning = array_values(array_filter($lots, fn ($l) => $l['days_left'] > 0 && $l['created_on'] < $ds));
                if (! $earning) continue;
                $opening = '0.00';
                foreach ($earning as $l) $opening = Money::add($opening, $l['amount']);
                $interest = Money::pct($opening, $rate);
                $mode = $u->compound_mode === 'cash' ? 'cash' : 'compound';

                $at = $day->copy()->setTime(0, 1)->setTimezone('UTC');
                if ($mode === 'cash') {
                    $this->plan->credit($userId, 'roi', $interest, 'ROI ' . (float) $rate . '% on compounding balance ' . Money::r($opening) . ' · ' . $ds, null, $at);
                } elseif (Money::pos($interest)) {
                    $id = DB::table('compound_lots')->insertGetId(['user_id' => $userId, 'order_id' => null, 'amount' => $interest, 'is_principal' => false, 'created_on' => $ds, 'days_left' => max(1, (int) ($s['roiDays'] ?: 25))]);
                    $lots[] = ['id' => $id, 'user_id' => $userId, 'order_id' => null, 'amount' => $interest, 'is_principal' => 0, 'created_on' => $ds, 'days_left' => max(1, (int) ($s['roiDays'] ?: 25))];
                }

                // age the lots that earned today; the ones that have just earned their last day expire
                $expiring = '0.00';
                foreach ($lots as &$l) {
                    if ($l['days_left'] <= 0 || $l['created_on'] >= $ds) continue;
                    $l['days_left']--;
                    DB::table('compound_lots')->where('id', $l['id'])->update(['days_left' => $l['days_left']]);
                    if ($l['is_principal']) DB::table('orders')->where('id', $l['order_id'])->increment('paid_days');
                    if ($l['days_left'] === 0) {
                        $expiring = Money::add($expiring, $l['amount']);
                        if ($l['is_principal'] && $l['order_id']) DB::table('orders')->where('id', $l['order_id'])->update(['status' => 'closed']);
                    }
                }
                unset($l);
                $closing = '0.00';
                foreach ($lots as $l) if ($l['days_left'] > 0) $closing = Money::add($closing, $l['amount']);

                DB::table('compound_ledger')->insertOrIgnore(['user_id' => $userId, 'day' => $ds, 'mode' => $mode, 'opening' => $opening, 'interest' => $interest, 'expiring' => $expiring, 'closing' => $closing]);
                $processed++;
            }
            DB::table('users')->where('id', $userId)->update(['compound_through' => $end->toDateString()]);
            Snapshot::forget($userId);
            return $processed;
        });
    }
}
