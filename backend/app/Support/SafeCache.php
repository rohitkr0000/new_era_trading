<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * A cache problem (full disk, a file locked by another process, Redis restarting) must slow a request down, never fail it:
 * on any cache error the value is simply computed again.
 */
final class SafeCache
{
    public static function remember(string $key, int $seconds, callable $compute)
    {
        if ($seconds <= 0) return $compute();
        try {
            return Cache::remember($key, $seconds, $compute);
        } catch (\Throwable $e) {
            report($e);
            return $compute();
        }
    }

    public static function forget(string $key): void
    {
        try { Cache::forget($key); } catch (\Throwable $e) { report($e); }
    }
}
