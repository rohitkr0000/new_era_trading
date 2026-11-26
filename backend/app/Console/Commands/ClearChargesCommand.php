<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Wipes ONLY the "Charges collected" log the admin's Company Wallet page reads (the deductions table) —
 * withdrawal fees, P2P fees, and forfeited referral income. Nothing else is touched: no user, order, ledger,
 * wallet or deposit/withdrawal row is changed. Use this to clear test-generated charges without the broader
 * reset app:clear-test-data does (which also deletes test members and everything tied to them).
 *
 *   php artisan app:clear-charges               (dry run — shows what would happen, changes nothing)
 *   php artisan app:clear-charges --force        (actually deletes, after a database backup)
 *   php artisan app:clear-charges --force --skip-backup
 */
class ClearChargesCommand extends Command
{
    protected $signature = 'app:clear-charges {--force : actually delete (default is a dry run)} {--skip-backup : skip the automatic backup before deleting}';
    protected $description = 'Delete every row in the Company Wallet "Charges collected" log — only that, nothing else';

    public function handle(): int
    {
        $byType = DB::table('deductions')->selectRaw('type, count(*) as n, sum(amount) as total')->groupBy('type')->get();
        $count = (int) DB::table('deductions')->count();

        if ($count === 0) { $this->info('Nothing to delete — the charges log is already empty.'); return self::SUCCESS; }

        $this->info('Rows that will be DELETED, by type:');
        $this->table(['type', 'count', 'total amount'], $byType->map(fn ($r) => [$r->type ?: '(none)', $r->n, number_format((float) $r->total, 2)])->all());
        $this->newLine();
        $this->warn("Total deductions rows that will be DELETED: {$count}");
        $this->line('Nothing else is touched — no user, order, ledger, wallet, deposit or withdrawal row changes.');

        if (! $this->option('force')) {
            $this->newLine();
            $this->line('This was a dry run — nothing was changed. Re-run with --force to actually delete.');
            return self::SUCCESS;
        }

        if (! $this->option('skip-backup')) {
            $this->info('Backing up the database first…');
            Artisan::call('app:backup', ['--no-files' => true], $this->output);
        }

        DB::table('deductions')->delete();

        $this->newLine();
        $this->info("Deleted {$count} row(s) from the charges log. Everything else is untouched.");
        return self::SUCCESS;
    }
}
