<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Before going live: delete every member created while testing, keeping only the company root account
 * (the one with no sponsor — normally NET1001) and any admin account. Plan settings (deposit address,
 * ROI %, min join amount …) are never touched.
 *
 *   php artisan app:clear-test-data               (dry run — shows what would happen, changes nothing)
 *   php artisan app:clear-test-data --force        (actually deletes, after a database backup)
 *   php artisan app:clear-test-data --force --skip-backup
 */
class ClearTestDataCommand extends Command
{
    protected $signature = 'app:clear-test-data {--force : actually delete (default is a dry run)} {--skip-backup : skip the automatic backup before deleting}';
    protected $description = 'Delete all test members before going live, keeping the company root account and admins';

    public function handle(): int
    {
        $keep = User::where('role', 'admin')->orWhereNull('sponsor_id')->get(['id', 'code', 'email', 'role', 'name']);
        $keepIds = $keep->pluck('id')->all();
        if (! $keepIds) { $this->error('No admin / root account found — refusing to run (this would delete everybody).'); return self::FAILURE; }

        $deleteIds = User::whereNotIn('id', $keepIds)->pluck('id')->all();
        $count = count($deleteIds);

        $this->info('Accounts that will be KEPT:');
        $this->table(['id', 'code', 'role', 'name', 'email'], $keep->map(fn ($u) => [$u->id, $u->code, $u->role, $u->name, $u->email])->all());
        $this->newLine();
        $this->warn("Members that will be DELETED: {$count}");
        if ($count === 0) { $this->info('Nothing to delete.'); return self::SUCCESS; }

        if (! $this->option('force')) {
            $this->newLine();
            $this->line('This was a dry run — nothing was changed. Re-run with --force to actually delete.');
            return self::SUCCESS;
        }

        // KYC documents belong to the members being deleted: their DB rows cascade away with the user,
        // but the uploaded files on disk do not delete themselves.
        $kycPaths = DB::table('kyc_submissions')->whereIn('user_id', $deleteIds)
            ->get(['front_path', 'back_path', 'selfie_path'])
            ->flatMap(fn ($r) => [$r->front_path, $r->back_path, $r->selfie_path])
            ->filter()->all();

        if (! $this->option('skip-backup')) {
            $this->info('Backing up the database first…');
            Artisan::call('app:backup', ['--no-files' => true], $this->output);
        }

        DB::transaction(function () use ($keepIds, $deleteIds) {
            User::whereIn('id', $deleteIds)->delete();   // cascades: orders, ledger, wallet_ledger, deposits, withdrawals,
                                                           // transfers, deductions, tickets, otps, kyc_submissions, compound_*
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $deleteIds)->delete();
            DB::table('risk_alerts')->delete();           // fresh start: alerts about deleted test members would be noise
            User::whereIn('id', $keepIds)->update([
                'invested' => 0, 'wallet' => 0, 'purchase_wallet' => 0, 'left_bv' => 0, 'right_bv' => 0,
                'team_size' => 0, 'team_business' => 0, 'compound_through' => null,
            ]);
        });

        foreach ($kycPaths as $p) { Storage::disk('local')->delete($p); }

        $this->newLine();
        $this->info("Deleted {$count} test member(s) and " . count($kycPaths) . ' KYC file(s). Plan settings were not touched.');
        $this->line('Note: audit_logs are kept on purpose (the trail is meant to outlive the accounts it mentions).');
        return self::SUCCESS;
    }
}
