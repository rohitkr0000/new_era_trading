<?php

namespace App\Console\Commands;

use App\Mail\BrandedMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/** Sends one real branded e-mail so you can check SMTP, the sender name and spam placement before launch. */
class TestMailCommand extends Command
{
    protected $signature = 'app:test-mail {to : address to send the test message to}';
    protected $description = 'Send a test e-mail (same look as the OTP mail) to check SMTP settings';

    public function handle(): int
    {
        $to = $this->argument('to');
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) { $this->error('Give a valid e-mail address.'); return self::FAILURE; }
        $this->line('Mailer: ' . config('mail.default') . '   From: ' . config('mail.from.name') . ' <' . config('mail.from.address') . '>');
        if (in_array(config('mail.default'), ['log', 'array'], true)) $this->warn('MAIL_MAILER is "' . config('mail.default') . '" - nothing is really sent (see storage/logs/laravel.log).');
        try {
            Mail::to($to)->send(new BrandedMail('123456 is your New Era Trading verification code (test)', 'otp', ['name' => 'Tester', 'code' => '123456', 'purpose' => 'SMTP test', 'minutes' => 10]));
        } catch (\Throwable $e) {
            $this->error('Sending failed: ' . $e->getMessage());
            return self::FAILURE;
        }
        $this->info("Sent to $to. Check the inbox AND the spam folder; the sender must read \"New Era Trading\".");
        return self::SUCCESS;
    }
}
