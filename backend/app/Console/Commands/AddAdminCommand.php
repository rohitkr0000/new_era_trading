<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

/**
 * Create an extra admin login after the first install (app:install only ever creates one, and refuses
 * once the database has accounts). Nothing in the admin panel lists admin accounts — only members — so
 * a second admin made this way never shows up to the first one.
 *
 *   php artisan app:add-admin --email=owner@neweratrading.net --name="Owner"
 *   (leave out --password to get a strong one generated and printed once)
 */
class AddAdminCommand extends Command
{
    protected $signature = 'app:add-admin {--email=} {--name=Administrator} {--password= : at least 12 characters (leave out to generate one)}';
    protected $description = 'Create another admin account (not shown anywhere members or other admins can see)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->option('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) { $this->error('Give a valid --email.'); return self::FAILURE; }
        if (User::where('email', $email)->exists()) { $this->error('An account with that e-mail already exists.'); return self::FAILURE; }

        $pass = (string) $this->option('password');
        $generated = false;
        if ($pass === '') { $pass = Str::password(16, symbols: false); $generated = true; }
        $v = Validator::make(['password' => $pass], ['password' => Password::min(12)->letters()->numbers()]);
        if ($v->fails()) { $this->error(implode(' ', $v->errors()->all())); return self::FAILURE; }
        if (in_array(strtolower($pass), ['admin123', 'password1234', 'administrator'], true)) { $this->error('That password is far too common.'); return self::FAILURE; }

        $now = Carbon::now();
        $admin = User::create([
            'name' => $this->option('name'), 'email' => $email, 'password' => $pass, 'role' => 'admin',
            'joined_at' => $now, 'email_verified_at' => $now,
        ]);

        $this->newLine();
        $this->info('Admin account created.');
        $this->table(['What', 'Value'], [
            ['Login', $email],
            ['Password', $generated ? $pass . '   <- shown ONLY now, save it' : '(the one you gave)'],
        ]);
        $this->line('Sign in at /login.html like any admin. This account never appears in the members list, leaderboards, or anyone\'s team — it has no sponsor and role=admin is always filtered out of those.');
        return self::SUCCESS;
    }
}
