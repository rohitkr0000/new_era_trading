<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Snapshot;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cleanly undoes ONE untouched package purchase (an admin mistake, or a test purchase on a real account) without
 * touching the buyer's sponsor tree or downline — it only reverses what invest() did for this one order: the
 * buyer's invested total, the business volume it added up the binary tree, and (with --claw-back) any
 * direct/retopup/level income it already paid out.
 *
 * Refuses unless the order has made zero progress (no ROI day paid, no compounding growth, no pending interest,
 * not matured) — anything further along needs a manual, case-by-case fix, not a blunt auto-undo.
 */
class UndoPurchaseCommand extends Command
{
    protected $signature = 'app:undo-purchase {ref : the order reference, e.g. CMP-1234 or ROI-1234} {--claw-back : also reverse any direct/retopup/level income this purchase already paid out}';
    protected $description = 'Delete one untouched package purchase and roll back its invested/business-volume effects';

    public function handle(): int
    {
        $ref = trim((string) $this->argument('ref'));
        $order = DB::table('orders')->where('ref', $ref)->first();
        if (! $order) { $this->error('No order with ref ' . $ref); return self::FAILURE; }
        if ((int) $order->paid_days > 0) { $this->error('This order has already paid ' . $order->paid_days . ' ROI day(s) — refusing to auto-undo it.'); return self::FAILURE; }
        if (in_array($order->status, ['matured', 'closed'], true)) { $this->error('This order is already ' . $order->status . ' — refusing to auto-undo it.'); return self::FAILURE; }
        if (Money::pos($order->pending_interest ?? '0')) { $this->error('This package already has pending interest — refusing to auto-undo it.'); return self::FAILURE; }

        $lots = DB::table('compound_lots')->where('order_id', $order->id)->get();
        foreach ($lots as $lot) {
            if (Money::cmp($lot->amount, $order->amount) !== 0 || (int) $lot->days_left === 0) {
                $this->error('This package has already grown or matured — refusing to auto-undo it. Handle it manually.');
                return self::FAILURE;
            }
        }

        $buyer = User::find($order->user_id);
        if (! $buyer) { $this->error('Buyer not found.'); return self::FAILURE; }

        $clawBack = (bool) $this->option('claw-back');
        if (! $this->confirm('Undo order ' . $ref . ' (' . $buyer->name . ' #' . $buyer->code . ', ' . Money::r($order->amount) . ')' . ($clawBack ? ' AND claw back any commission it paid' : '') . '? This is not reversible automatically.')) {
            $this->warn('Cancelled.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($order, $clawBack) {
            DB::table('compound_lots')->where('order_id', $order->id)->delete();

            if ($clawBack) {
                $rows = DB::table('ledger')->where(function ($q) use ($order) {
                    $q->where('order_id', $order->id)
                        ->orWhere(function ($q2) use ($order) {
                            $q2->where('from_id', $order->user_id)->whereIn('type', ['direct', 'retopup'])
                                ->whereBetween('created_at', [
                                    Carbon::parse($order->created_at)->subMinute(),
                                    Carbon::parse($order->created_at)->addMinute(),
                                ]);
                        });
                })->whereIn('type', ['direct', 'retopup', 'level'])->get();

                $log = [];
                foreach ($rows as $r) {
                    if (! Money::pos($r->amount)) continue;
                    $recipient = User::lockForUpdate()->find($r->user_id);
                    if (! $recipient) continue;
                    $recipient->wallet = Money::sub($recipient->wallet, $r->amount);
                    $recipient->save();
                    DB::table('ledger')->insert([
                        'user_id' => $recipient->id, 'type' => $r->type, 'amount' => Money::sub('0', $r->amount),
                        'note' => 'Reversed: ' . $r->note . ' (order ' . $order->ref . ' cancelled)',
                        'from_id' => $r->from_id, 'order_id' => null, 'created_at' => Carbon::now(),
                    ]);
                    Snapshot::forget($recipient->id);
                    $log[] = '#' . $recipient->code . ' ' . $r->type . ' ' . Money::r($r->amount);
                }
                $this->line($log ? ('Clawed back: ' . implode(', ', $log)) : 'Nothing to claw back — no commission had been paid on this order.');
            }

            $buyer = User::lockForUpdate()->find($order->user_id);
            $buyer->invested = Money::cmp($buyer->invested, $order->amount) > 0 ? Money::sub($buyer->invested, $order->amount) : '0.00';
            $buyer->save();

            // undo the business volume this purchase added up the binary tree (mirrors PlanEngine::invest())
            $child = $buyer;
            $anc = $child->parent_id ? User::lockForUpdate()->find($child->parent_id) : null;
            while ($anc) {
                $col = $child->leg === 'left' ? 'left_bv' : 'right_bv';
                $anc->$col = Money::cmp($anc->$col, $order->amount) > 0 ? Money::sub($anc->$col, $order->amount) : '0.00';
                $anc->save();
                $child = $anc;
                $anc = $anc->parent_id ? User::lockForUpdate()->find($anc->parent_id) : null;
            }

            DB::table('orders')->where('id', $order->id)->delete();
            Snapshot::forget($buyer->id);
            Audit::log('admin.undo-purchase', null, 'order#' . $order->id, ['ref' => $order->ref, 'buyer' => $buyer->code, 'amount' => $order->amount, 'clawBack' => $clawBack]);
        });

        $fresh = User::find($order->user_id);
        $this->info('Order ' . $ref . ' undone. ' . $fresh->name . ' (#' . $fresh->code . ') invested is now ' . Money::r($fresh->invested) . '.');
        return self::SUCCESS;
    }
}
