<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * First-time production setup — creates the tables, the admin login and the company root account (NET1001)
 * that the first real members register under. No demo members, no demo passwords, never wipes existing data.
 *
 *   php artisan app:install --admin-email=you@neweratrading.net --deposit-address=0x...
 */
class InstallCommand extends Command
{
    protected $signature = 'app:install
        {--admin-email= : e-mail the admin signs in with}
        {--admin-name=Administrator}
        {--admin-password= : at least 12 characters (leave out to generate a strong one)}
        {--company-email=company@neweratrading.net : internal address of the company root account (never signs in)}
        {--deposit-address= : company USDT (BEP20) wallet address that receives deposits}';
    protected $description = 'Create tables, the admin account and the company root account (no demo data)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->option('admin-email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) { $this->error('Give a valid --admin-email.'); return self::FAILURE; }
        $addr = (string) $this->option('deposit-address');
        if ($addr !== '' && ! preg_match('/^0x[0-9a-fA-F]{40}$/', $addr)) { $this->error('--deposit-address must look like 0x followed by 40 hex characters.'); return self::FAILURE; }
        $pass = (string) $this->option('admin-password');
        $generated = false;
        if ($pass === '') { $pass = Str::password(16, symbols: false); $generated = true; }
        if (strlen($pass) < 12) { $this->error('Admin password must be at least 12 characters.'); return self::FAILURE; }
        if (in_array(strtolower($pass), ['admin123', 'password1234', 'administrator'], true)) { $this->error('That password is far too common.'); return self::FAILURE; }

        Artisan::call('migrate', ['--force' => true]);
        $this->line(trim(Artisan::output()) ?: 'Migrations are up to date.');

        if (Schema::hasTable('users') && User::count() > 0) {
            $this->error('This database already has accounts. app:install only runs on an empty database (it never deletes data).');
            return self::FAILURE;
        }

        $now = Carbon::now();
        $company = User::create([
            'name' => 'New Era Trading', 'email' => strtolower($this->option('company-email')), 'password' => Str::random(40), 'code' => 'NET1001', 'role' => 'member',
            'phone' => null, 'joined_at' => $now, 'email_verified_at' => $now, 'kyc_status' => 'approved',
        ]);
        $admin = User::create([
            'name' => $this->option('admin-name'), 'email' => $email, 'password' => $pass, 'code' => 'ADMIN', 'role' => 'admin',
            'joined_at' => $now, 'email_verified_at' => $now,
        ]);
        if ($addr !== '') Settings::update(['depositAddress' => $addr]);

        $this->newLine();
        $this->info('Installed.');
        $this->table(['What', 'Value'], [
            ['Admin login', $email],
            ['Admin password', $generated ? $pass . '   <- shown ONLY now, save it' : '(the one you gave)'],
            ['Company / first sponsor code', $company->code . '   (members register with ?sponsor=NET1001)'],
            ['Deposit address', $addr ?: 'NOT SET - set it in Admin -> Plan Settings before going live'],
        ]);
        $this->line('Next: php artisan app:check-production');
        return self::SUCCESS;
    }
}
