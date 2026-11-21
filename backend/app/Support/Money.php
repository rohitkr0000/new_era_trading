<?php

namespace App\Support;

/**
 * Exact money maths on decimal strings (bcmath, 2 decimal places, half-up rounding).
 * Never use floats for balances.
 */
final class Money
{
    public static function r($v): string
    {
        return self::round2(self::str($v));
    }

    public static function add($a, $b): string { return bcadd(self::str($a), self::str($b), 2); }
    public static function sub($a, $b): string { return bcsub(self::str($a), self::str($b), 2); }
    public static function mul($a, $b): string { return self::round2(bcmul(self::str($a), self::str($b), 8)); }
    public static function cmp($a, $b): int { return bccomp(self::str($a), self::str($b), 2); }
    public static function pos($a): bool { return self::cmp($a, '0') > 0; }

    /** amount × pct / 100, rounded half-up to cents */
    public static function pct($amount, $pct): string
    {
        return self::round2(bcdiv(bcmul(self::str($amount), self::str($pct), 10), '100', 10));
    }

    public static function round2(string $v): string
    {
        $neg = str_starts_with($v, '-');
        $r = bcadd(ltrim($v, '-'), '0.005', 2);
        return ($neg && bccomp($r, '0', 2) !== 0 ? '-' : '') . $r;
    }

    /** numbers → plain decimal string bcmath accepts (no exponent notation) */
    public static function str($v): string
    {
        if (is_string($v) && is_numeric($v)) return $v;
        if (is_int($v)) return (string) $v;
        if (is_float($v)) return rtrim(rtrim(number_format($v, 10, '.', ''), '0'), '.') ?: '0';
        return is_numeric($v) ? (string) $v : '0';
    }

    public static function f($v): float { return (float) $v; }
}
