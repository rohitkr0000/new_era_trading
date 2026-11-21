<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Append-only audit trail: who did what, from which IP, and when.
 * Never throws — a logging problem must not break the action being logged.
 */
final class Audit
{
    /** Keys that must never be written to the log. */
    private const SECRET = ['password', 'new', 'old', 'pin', 'otp', 'token', 'code'];

    public static function log(string $action, ?User $actor = null, ?string $target = null, array $meta = []): void
    {
        try {
            $actor ??= request()?->user();
            DB::table('audit_logs')->insert([
                'actor_id' => $actor?->id,
                'actor' => $actor ? mb_substr($actor->name . ' <' . $actor->email . '>', 0, 160) : null,
                'action' => $action,
                'target' => $target ? mb_substr($target, 0, 80) : null,
                'meta' => $meta ? json_encode(self::clean($meta), JSON_UNESCAPED_UNICODE) : null,
                'ip' => request()?->ip(),
                'created_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function clean(array $meta): array
    {
        foreach ($meta as $k => $v) {
            if (in_array(strtolower((string) $k), self::SECRET, true)) $meta[$k] = '***';
            elseif (is_array($v)) $meta[$k] = self::clean($v);
        }
        return $meta;
    }
}
