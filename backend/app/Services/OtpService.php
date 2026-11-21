<?php

namespace App\Services;

use App\Models\User;
use App\Support\PlanException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * E-mail one-time passwords: 6 digits, stored hashed, valid 10 minutes, 5 attempts, 1 new code per 60 seconds.
 * The code is only ever sent by e-mail — it is never returned by the API.
 */
class OtpService
{
    public const TTL_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_SECONDS = 60;

    /** A short fingerprint of the request a code is for (amount, recipient, address …). */
    public static function context(...$parts): string
    {
        return substr(hash('sha256', implode('|', array_map(fn ($p) => is_scalar($p) ? (string) $p : json_encode($p), $parts))), 0, 64);
    }

    /** $context binds the code to one request: a code for "$5 to NET1002" cannot approve "$500 to NET1003". */
    public function send(User $u, string $purpose, string $label, ?string $context = null): void
    {
        $last = DB::table('otps')->where('user_id', $u->id)->where('purpose', $purpose)->orderByDesc('id')->first();
        if ($last && Carbon::parse($last->created_at, 'UTC')->diffInSeconds(Carbon::now(), false) < self::RESEND_SECONDS) {
            throw new PlanException('Please wait a minute before requesting another code.');
        }
        DB::table('otps')->where('user_id', $u->id)->where('purpose', $purpose)->delete();   // only the newest code is valid

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::table('otps')->insert([
            'user_id' => $u->id, 'purpose' => $purpose, 'code_hash' => Hash::make($code), 'context' => $context,
            'attempts' => 0, 'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES), 'created_at' => Carbon::now(),
        ]);
        Notifier::otp($u, $code, $label, self::TTL_MINUTES);
    }

    /** Throws PlanException unless $code is the valid, unexpired code for this purpose. A correct code is consumed. */
    public function verify(User $u, string $purpose, string $code, ?string $context = null): void
    {
        $row = DB::table('otps')->where('user_id', $u->id)->where('purpose', $purpose)->orderByDesc('id')->first();
        if (! $row) throw new PlanException('Request a verification code first.');
        if (Carbon::parse($row->expires_at, 'UTC')->isPast()) { DB::table('otps')->where('id', $row->id)->delete(); throw new PlanException('This code has expired. Request a new one.'); }
        if ($row->attempts >= self::MAX_ATTEMPTS) { DB::table('otps')->where('id', $row->id)->delete(); throw new PlanException('Too many wrong attempts. Request a new code.'); }
        if ($row->context !== null && ! hash_equals((string) $row->context, (string) $context)) {
            DB::table('otps')->where('id', $row->id)->delete();   // a code issued for another request is burnt, never reusable
            throw new PlanException('This code was issued for a different request. Start again and request a new code.');
        }
        if (! preg_match('/^\d{6}$/', $code) || ! Hash::check($code, $row->code_hash)) {
            DB::table('otps')->where('id', $row->id)->increment('attempts');
            throw new PlanException('Incorrect verification code.');
        }
        DB::table('otps')->where('id', $row->id)->delete();
    }
}
