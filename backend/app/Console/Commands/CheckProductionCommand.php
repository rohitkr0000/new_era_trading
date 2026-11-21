<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Go-live checklist. Every line is PASS / WARN / FAIL; the exit code is 1 if anything FAILs.
 */
class CheckProductionCommand extends Command
{
    protected $signature = 'app:check-production';
    protected $description = 'Verify the configuration is safe for a public launch';

    private array $rows = [];
    private int $fails = 0;

    private function add(string $level, string $what, string $detail = ''): void
    {
        if ($level === 'FAIL') $this->fails++;
        $this->rows[] = [$level, $what, $detail];
    }

    private function ok(bool $cond, string $what, string $fixHint, string $level = 'FAIL'): void
    {
        $cond ? $this->add('PASS', $what) : $this->add($level, $what, $fixHint);
    }

    public function handle(): int
    {
        $env = config('app.env');
        $this->ok($env === 'production', 'APP_ENV is production', "now '$env' - set APP_ENV=production");
        $this->ok(! config('app.debug'), 'APP_DEBUG is off', 'set APP_DEBUG=false (debug pages leak secrets)');
        $this->ok(! config('app.demo_mode'), 'DEMO_MODE is off', 'set DEMO_MODE=false (otherwise sign-ups get free simulated money)');
        $this->ok(! config('app.accrue_on_request'), 'ACCRUE_ON_REQUEST is off', 'set ACCRUE_ON_REQUEST=false and run the scheduler instead');
        $this->ok(strlen((string) config('app.key')) > 20, 'APP_KEY is set', 'run php artisan key:generate');
        $this->ok(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL uses https', 'APP_URL=https://your-domain');
        $this->ok(str_starts_with((string) config('app.frontend_url'), 'https://'), 'FRONTEND_URL uses https', 'FRONTEND_URL=https://your-domain');

        $mailer = config('mail.default');
        $this->ok(! in_array($mailer, ['log', 'array'], true), 'Mail goes out for real (MAIL_MAILER)', "now '$mailer' - OTPs would only be written to a log file; set SMTP", 'FAIL');
        if ($mailer === 'smtp') {
            $this->ok(! in_array(config('mail.mailers.smtp.host'), ['127.0.0.1', 'localhost', null, ''], true), 'SMTP host is a real server', 'set MAIL_HOST');
            $this->ok((bool) config('mail.mailers.smtp.username'), 'SMTP username is set', 'set MAIL_USERNAME / MAIL_PASSWORD', 'WARN');
        }
        $from = (string) config('mail.from.address');
        $this->ok(str_ends_with($from, '@neweratrading.net'), 'Mail sender is on your domain', "now $from - use no-reply@neweratrading.net so SPF/DKIM can pass", 'WARN');
        $this->ok(config('mail.from.name') === 'New Era Trading', 'Mail sender name is "New Era Trading"', 'set MAIL_FROM_NAME="New Era Trading"');

        $origins = (array) config('cors.allowed_origins');
        $this->ok(! in_array('*', $origins, true), 'CORS limited to your site', 'set CORS_ALLOWED_ORIGINS=https://your-domain (or leave * only if the site and API share one domain)', 'WARN');
        $this->ok((int) config('sanctum.expiration') > 0, 'Login tokens expire', 'set SANCTUM_EXPIRATION (minutes)');
        $this->ok((bool) config('app.admin_2fa'), 'Admin sign-in needs an e-mailed code (ADMIN_2FA)', 'set ADMIN_2FA=true - admins can approve payouts');
        $this->ok(! in_array(config('cache.default'), ['database', 'array'], true), 'Cache store is fast (not database)', 'set CACHE_STORE=file (or redis) - with the database store every request makes an extra query', 'WARN');
        $this->ok(config('sanctum.last_used_at') === false, 'No database write per API call (Sanctum last_used_at off)', 'set last_used_at => false in config/sanctum.php');

        try {
            DB::connection()->getPdo();
            $this->add('PASS', 'Database reachable (' . DB::getDriverName() . ')');
            $this->ok(! in_array(config('database.connections.' . config('database.default') . '.username'), ['root', null, ''], true), 'The website does not use the database root account', 'create a dedicated user: deploy/database/setup-database.sql');
            $pending = collect(app('migrator')->getMigrationFiles(database_path('migrations')))->keys()
                ->diff(DB::table('migrations')->pluck('migration'))->count();
            $this->ok($pending === 0, 'All migrations applied', "$pending migration(s) pending - run php artisan migrate --force");

            $demo = User::whereIn('email', ['demo@mlm.com', 'admin@mlm.com'])->orWhere('email', 'like', '%@example.com')->count();
            $this->ok($demo === 0, 'No demo accounts', "$demo demo account(s) found - production needs a clean database: php artisan migrate:fresh --force && php artisan app:install ...");
            $this->ok(User::where('role', 'admin')->count() >= 1, 'An admin account exists', 'run php artisan app:install');
            $this->ok(User::where('code', 'NET1001')->exists(), 'Company root account NET1001 exists', 'run php artisan app:install');
            $s = Settings::all();
            $this->ok(preg_match('/^0x[0-9a-fA-F]{40}$/', (string) $s['depositAddress']) === 1 && ! str_contains($s['depositAddress'], 'DEMO'), 'Company deposit address is a real wallet', 'set it in Admin -> Plan Settings');
            $this->ok(! empty($s['kycRequired']), 'KYC is required for withdrawals', 'enable it in Plan Settings', 'WARN');
            $this->ok(! empty($s['emailVerifyRequired']), 'E-mail verification is required for withdrawals', 'enable it in Plan Settings', 'WARN');
        } catch (\Throwable $e) {
            $this->add('FAIL', 'Database', $e->getMessage());
        }

        $this->ok((bool) config('services.chain.key'), 'On-chain deposit check (ETHERSCAN_API_KEY)', 'without it every deposit must be checked by hand on bscscan.com', 'WARN');

        $hb = cache('net.scheduler.last');
        $this->ok($hb && Carbon::parse($hb)->gt(now()->subMinutes(30)), 'Scheduler ran in the last 30 minutes (daily ROI)', 'add the cron line: * * * * * php artisan schedule:run', 'WARN');
        $bk = cache('net.backup.last');
        $this->ok($bk && Carbon::parse($bk)->gt(now()->subHours(48)), 'A backup exists from the last 48 hours', 'run php artisan app:backup (it is scheduled daily at 02:30)', 'WARN');
        $this->ok(is_writable(storage_path('app')) && is_writable(storage_path('logs')), 'storage/ is writable', 'chmod -R 775 storage bootstrap/cache');
        $this->ok(! is_file(public_path('.env')), 'public/ does not contain .env', 'remove public/.env');

        $this->table(['', 'Check', 'Fix / detail'], $this->rows);
        $warns = count(array_filter($this->rows, fn ($r) => $r[0] === 'WARN'));
        $this->line(($this->fails ? "<fg=red>{$this->fails} FAIL</>" : '<fg=green>0 FAIL</>') . ", $warns WARN");
        if ($this->fails) $this->error('Not ready for a public launch.');
        else $this->info($warns ? 'No blockers. Review the warnings.' : 'All checks passed.');
        return $this->fails ? self::FAILURE : self::SUCCESS;
    }
}
