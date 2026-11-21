<?php

namespace App\Services;

use App\Mail\BrandedMail;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * All e-mail the platform sends, always as "New Era Trading".
 * A mail failure is logged but never breaks the action that triggered it (a sign-up or withdrawal must still succeed).
 */
class Notifier
{
    private static function send(User $to, string $subject, string $template, array $data = []): void
    {
        $deliver = function () use ($to, $subject, $template, $data) {
            try {
                Mail::to($to->email, $to->name)->send(new BrandedMail($subject, $template, $data + ['name' => $to->name]));
            } catch (\Throwable $e) {
                report($e);
            }
        };
        // web request: send after the response has gone out (the member never waits for SMTP); console / tests: send now
        app()->runningInConsole() ? $deliver() : defer($deliver);
    }

    private static function when($ts): string
    {
        $tz = Settings::get('timezone');
        return Carbon::parse($ts, 'UTC')->setTimezone($tz)->format('d M Y, h:i A') . ' ' . Settings::get('tzLabel');
    }

    private static function maskAccount(string $a): string
    {
        return strlen($a) > 14 ? substr($a, 0, 6) . '…' . substr($a, -4) : $a;
    }

    /** Registration message. */
    public static function welcome(User $u): void
    {
        $s = Settings::all();
        $sponsor = $u->sponsor_id ? User::find($u->sponsor_id) : null;
        self::send($u, 'Welcome to New Era Trading — your account is ready', 'welcome', [
            'code' => $u->code, 'sponsor' => $sponsor?->name, 'phone' => $u->phone,
            'link' => rtrim(config('app.frontend_url'), '/') . '/register.html?sponsor=' . $u->code,
            'loginUrl' => rtrim(config('app.frontend_url'), '/') . '/login.html',
            'minJoin' => $s['currency'] . (float) $s['minJoin'], 'window' => Market::status()['window'],
        ]);
    }

    /** Withdrawal request received. */
    public static function withdrawalRequested(int $withdrawalId): void
    {
        $w = DB::table('withdrawals')->where('id', $withdrawalId)->first();
        $u = $w ? User::find($w->user_id) : null;
        if (! $u) return;
        $c = Settings::get('currency');
        self::send($u, 'Withdrawal request received — ' . $w->ref, 'withdrawal-requested', [
            'ref' => $w->ref, 'amount' => $c . $w->amount, 'fee' => $c . $w->fee, 'net' => $c . $w->net, 'method' => $w->method,
            'account' => self::maskAccount($w->account), 'when' => self::when($w->created_at), 'due' => self::when($w->due_at),
            'hours' => Settings::get('receiptHours'),
        ]);
    }

    /** Withdrawal approved / rejected by the admin. */
    public static function withdrawalProcessed(int $withdrawalId): void
    {
        $w = DB::table('withdrawals')->where('id', $withdrawalId)->first();
        $u = $w ? User::find($w->user_id) : null;
        if (! $u) return;
        $c = Settings::get('currency');
        $ok = $w->status === 'approved';
        self::send($u, ($ok ? 'Withdrawal approved — ' : 'Withdrawal rejected — ') . $w->ref, 'withdrawal-status', [
            'ref' => $w->ref, 'approved' => $ok, 'amount' => $c . $w->amount, 'net' => $c . $w->net, 'method' => $w->method,
            'account' => self::maskAccount($w->account), 'when' => self::when($w->processed_at ?? $w->created_at),
            'txid' => $w->payout_txid ?? null, 'note' => $w->admin_note ?? null,
        ]);
    }

    /** One-time password (password change …). */
    public static function otp(User $u, string $code, string $purposeLabel, int $minutes): void
    {
        self::send($u, $code . ' is your New Era Trading verification code', 'otp', ['code' => $code, 'purpose' => $purposeLabel, 'minutes' => $minutes]);
    }

    /** Security notice after the password was changed. */
    public static function passwordChanged(User $u): void
    {
        self::send($u, 'Your New Era Trading password was changed', 'password-changed', ['when' => self::when(Carbon::now())]);
    }

    /** Security notice: a sign-in from a new place is not tracked yet; this one reports a password reset by e-mail. */
    public static function passwordReset(User $u): void
    {
        self::send($u, 'Your New Era Trading password was reset', 'password-changed', ['when' => self::when(Carbon::now()), 'reset' => true]);
    }

    /** P2P transfer: a notice to the sender and one to the receiver. */
    public static function transferDone(User $from, User $to, string $amount, string $fee, string $net): void
    {
        $c = Settings::get('currency');
        $data = ['amount' => $c . $amount, 'fee' => $c . $fee, 'net' => $c . $net, 'when' => self::when(Carbon::now())];
        self::send($from, 'You sent ' . $c . $amount . ' to ' . $to->code, 'transfer', $data + ['sent' => true, 'other' => $to->name . ' (' . $to->code . ')']);
        self::send($to, 'You received ' . $c . $net . ' from ' . $from->code, 'transfer', $data + ['sent' => false, 'other' => $from->name . ' (' . $from->code . ')']);
    }

    /** KYC decision. */
    public static function kycResult(User $u, bool $approved, ?string $note): void
    {
        self::send($u, $approved ? 'Your KYC is approved' : 'Your KYC needs attention', 'kyc-status', ['approved' => $approved, 'note' => $note]);
    }
}
