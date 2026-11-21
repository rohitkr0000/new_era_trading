<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Plan settings: config/plan.php defaults overridden by rows in the `settings` table.
 */
final class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $saved = SafeCache::remember('net.settings.saved', 60, fn () => DB::table('settings')->pluck('value', 'key')->map(fn ($v) => json_decode($v, true))->all());
            self::$cache = array_merge(config('plan'), array_intersect_key($saved, config('plan')));
        }
        return self::$cache;
    }

    public static function get(string $key)
    {
        return self::all()[$key] ?? null;
    }

    public static function update(array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (! array_key_exists($key, config('plan'))) continue;
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value)]);
        }
        self::$cache = null;
        SafeCache::forget('net.settings.saved');
        return self::all();
    }

    public static function flush(): void { self::$cache = null; SafeCache::forget('net.settings.saved'); }
}
