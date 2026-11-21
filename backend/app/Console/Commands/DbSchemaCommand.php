<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Writes the real database structure (tables, columns, indexes, foreign keys) to deploy/database/SCHEMA.md. */
class DbSchemaCommand extends Command
{
    protected $signature = 'app:db-schema {--out= : output file (default deploy/database/SCHEMA.md)}';
    protected $description = 'Document the database structure as Markdown';

    /** What each table is for (shown above its columns). */
    private const PURPOSE = [
        'users' => 'Every account: members and admins. Money balances (wallet = income wallet, purchase_wallet), sponsor / binary placement, KYC state, compounding mode.',
        'orders' => 'Packages bought (joining / retopup). option = Investment (simple interest) or Compounding.',
        'ledger' => 'Income-wallet ledger: every credit and debit. A balance always equals the sum of its ledger rows.',
        'wallet_ledger' => 'Purchase-wallet ledger (deposits, package purchases, P2P in/out) with running balance.',
        'compound_lots' => 'Compounding: each live amount (the purchased amount and every day\'s interest) with its remaining earning days.',
        'compound_ledger' => 'Compounding: the daily schedule per member (opening, interest, expiring, closing). Unique per member and day = idempotent daily job.',
        'deposits' => 'USDT deposits submitted by members (txn_id is unique) with the blockchain-check result.',
        'withdrawals' => 'Withdrawal requests, fee / net, payout transaction hash set by the admin.',
        'transfers' => 'P2P and own-wallet transfers with the company charge (fee / net).',
        'deductions' => 'Company charges taken from members (withdrawal service charge, P2P charge).',
        'otps' => 'E-mailed one-time codes: stored hashed, expiring, attempt-limited and bound to one request (context).',
        'kyc_submissions' => 'KYC documents (files live outside the web root) and the admin decision.',
        'audit_logs' => 'Append-only trail of sign-ins, money requests and admin actions with IP.',
        'settings' => 'Plan settings overrides edited in Admin -> Plan Settings.',
        'personal_access_tokens' => 'Login tokens (hashed), with expiry.',
        'tickets' => 'Support tickets.', 'ticket_replies' => 'Replies on tickets.', 'announcements' => 'Dashboard announcements.',
        'cache' => 'Laravel cache (database store).', 'cache_locks' => 'Cache locks.', 'sessions' => 'Unused by the API (token auth).',
        'jobs' => 'Queue.', 'job_batches' => 'Queue batches.', 'failed_jobs' => 'Failed queue jobs.', 'migrations' => 'Which migrations have run.', 'password_reset_tokens' => 'Unused (password reset uses otps).',
    ];

    public function handle(): int
    {
        if (DB::getDriverName() !== 'mysql') { $this->error('Needs MySQL / MariaDB.'); return self::FAILURE; }
        $db = DB::getDatabaseName();
        $out = ["# New Era Trading — database structure", '', "Database `$db` (" . DB::selectOne('select version() v')->v . "), generated " . now()->toDateTimeString() . " by `php artisan app:db-schema`.", '',
            'Money columns are `DECIMAL(18,2)` — never floats. Every balance change is written to a ledger table. Timestamps are stored in UTC.', ''];

        $tables = DB::select("SELECT TABLE_NAME n, TABLE_ROWS r, ENGINE e FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", [$db]);
        $out[] = '## Tables'; $out[] = '';
        $out[] = '| Table | Purpose | Engine |'; $out[] = '|---|---|---|';
        foreach ($tables as $t) $out[] = "| `{$t->n}` | " . (self::PURPOSE[$t->n] ?? '') . " | {$t->e} |";
        $out[] = '';

        foreach ($tables as $t) {
            $out[] = "## `{$t->n}`"; $out[] = '';
            if (isset(self::PURPOSE[$t->n])) { $out[] = self::PURPOSE[$t->n]; $out[] = ''; }
            $out[] = '| Column | Type | Null | Default | Key / extra |'; $out[] = '|---|---|---|---|---|';
            foreach (DB::select('SELECT COLUMN_NAME c, COLUMN_TYPE t, IS_NULLABLE n, COLUMN_DEFAULT d, COLUMN_KEY k, EXTRA x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$db, $t->n]) as $c) {
                $out[] = "| `{$c->c}` | {$c->t} | " . ($c->n === 'YES' ? 'yes' : 'no') . ' | ' . ($c->d === null ? '' : '`' . $c->d . '`') . ' | ' . trim($c->k . ' ' . $c->x) . ' |';
            }
            $out[] = '';
            $idx = DB::select('SELECT INDEX_NAME i, NON_UNIQUE u, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? GROUP BY INDEX_NAME, NON_UNIQUE ORDER BY INDEX_NAME', [$db, $t->n]);
            if ($idx) { $out[] = '**Indexes:** ' . implode(' · ', array_map(fn ($i) => "`{$i->i}` (" . $i->cols . ')' . ($i->u ? '' : ' unique'), $idx)); $out[] = ''; }
            $fk = DB::select('SELECT COLUMN_NAME c, REFERENCED_TABLE_NAME rt, REFERENCED_COLUMN_NAME rc FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL', [$db, $t->n]);
            if ($fk) { $out[] = '**Foreign keys:** ' . implode(' · ', array_map(fn ($f) => "`{$f->c}` → `{$f->rt}.{$f->rc}`", $fk)); $out[] = ''; }
        }
        $file = $this->option('out') ?: base_path('../deploy/database/SCHEMA.md');
        file_put_contents($file, implode("\n", $out) . "\n");
        $this->info('Written ' . realpath($file) . ' (' . count($tables) . ' tables)');
        return self::SUCCESS;
    }
}
