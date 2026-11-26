<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Wipes pre-launch TEST financial activity (orders, ledger, wallet_ledger, deposits, withdrawals, transfers,
 * deductions, and the compound lots tied to deleted orders) dated before a cutoff, then rebuilds every member's
 * invested / wallet / purchase_wallet / left_bv / right_bv / team stats from whatever is left.
 *
 * No user account is ever deleted by this command — only financial records before the cutoff. KYC submissions,
 * support tickets and audit_logs are untouched.
 *
 *   php artisan app:reset-to-live                              (dry run — cutoff defaults to start of today)
 *   php artisan app:reset-to-live --before="2026-11-26 00:00:00"
 *   php artisan app:reset-to-live --force
 *   php artisan app:reset-to-live --force --skip-backup
 */
class ResetToLiveCommand extends Command
{
    protected $signature = 'app:reset-to-live {--before= : cutoff datetime (default: start of today) — rows created before this are deleted} {--force : actually delete (default is a dry run)} {--skip-backup : skip the automatic backup before deleting}';
    protected $description = 'Delete pre-launch test financial activity before a cutoff and rebuild every member\'s balances from what remains — never deletes a user account';

    private const TABLES = ['orders', 'ledger', 'wallet_ledger', 'deposits', 'withdrawals', 'transfers', 'deductions'];

    public function handle(): int
    {
        $cutoff = $this->option('before') ? Carbon::parse($this->option('before')) : Carbon::today();
        $this->info('Cutoff: ' . $cutoff->toDateTimeString() . ' — everything created BEFORE this is deleted, everything from then on is kept.');
        $this->warn('No user account is ever deleted by this command.');
        $this->newLine();

        $counts = [];
        foreach (self::TABLES as $t) $counts[$t] = DB::table($t)->where('created_at', '<', $cutoff)->count();
        $oldOrderIds = DB::table('orders')->where('created_at', '<', $cutoff)->pluck('id');
        $lotCount = (int) DB::table('compound_lots')->whereIn('order_id', $oldOrderIds)->count();

        $this->table(['table', 'rows before cutoff (will be deleted)'], collect($counts)->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->line('compound_lots tied to those orders: ' . $lotCount);

        if (array_sum($counts) === 0) { $this->info('Nothing before the cutoff — nothing to do.'); return self::SUCCESS; }

        if (! $this->option('force')) {
            $this->newLine();
            $this->line('This was a dry run — nothing was changed. Re-run with --force to actually do it.');
            return self::SUCCESS;
        }

        if (! $this->option('skip-backup')) {
            $this->info('Backing up the database first…');
            Artisan::call('app:backup', ['--no-files' => true], $this->output);
        }

        DB::transaction(function () use ($cutoff) {
            $oldOrderIds = DB::table('orders')->where('created_at', '<', $cutoff)->pluck('id');
            DB::table('compound_lots')->whereIn('order_id', $oldOrderIds)->delete();
            foreach (self::TABLES as $t) DB::table($t)->where('created_at', '<', $cutoff)->delete();
            DB::table('risk_alerts')->delete();   // stale alerts about now-purged activity would be noise

            // rebuild every member's running totals from whatever financial activity remains
            User::where('role', 'member')->update(['left_bv' => 0, 'right_bv' => 0]);
            foreach (User::where('role', 'member')->get(['id']) as $u) {
                $invested = Money::r(DB::table('orders')->where('user_id', $u->id)->sum('amount') ?: '0');
                $wallet = Money::r(DB::table('ledger')->where('user_id', $u->id)->sum('amount') ?: '0');

                // wallet_ledger.balance is a cumulative snapshot that still reflects now-deleted history — replay
                // whatever rows remain, oldest first, to both get the true current total and fix each row's
                // stored snapshot (the member's own Wallet Statement page displays that column directly).
                $running = '0.00';
                foreach (DB::table('wallet_ledger')->where('user_id', $u->id)->orderBy('id')->get(['id', 'amount']) as $row) {
                    $running = Money::add($running, $row->amount);
                    DB::table('wallet_ledger')->where('id', $row->id)->update(['balance' => $running]);
                }

                User::where('id', $u->id)->update([
                    'invested' => $invested,
                    'wallet' => Money::pos($wallet) ? $wallet : '0.00',
                    'purchase_wallet' => Money::pos($running) ? $running : '0.00',
                ]);
            }

            // replay business volume up the binary tree for every remaining order, oldest first (mirrors PlanEngine::invest())
            foreach (DB::table('orders')->orderBy('created_at')->orderBy('id')->get(['user_id', 'amount']) as $o) {
                $child = User::find($o->user_id);
                $anc = $child?->parent_id ? User::find($child->parent_id) : null;
                while ($anc) {
                    $col = $child->leg === 'left' ? 'left_bv' : 'right_bv';
                    $anc->$col = Money::add($anc->$col, $o->amount);
                    $anc->save();
                    $child = $anc;
                    $anc = $anc->parent_id ? User::find($anc->parent_id) : null;
                }
            }
        });

        Artisan::call('net:stats', [], $this->output);
        Cache::forget('net.snapshot.public');

        $this->newLine();
        $this->info('Done. Pre-cutoff test activity removed; every member\'s balances were rebuilt from what remains. No account was deleted.');
        return self::SUCCESS;
    }
}
