<?php

namespace App\Support;

/**
 * Mobile-number normalisation used for the "one account per mobile number" rule.
 * "+91 98765-43210", "09876543210" and "9876543210" all map to the same key (the last 10 digits).
 */
final class Phone
{
    public static function digits(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone);
    }

    /** @return string|null  null when the number is missing or not 8–15 digits long */
    public static function key(?string $phone): ?string
    {
        $d = self::digits($phone);
        if (strlen($d) < 8 || strlen($d) > 15) return null;
        return strlen($d) > 10 ? substr($d, -10) : $d;
    }
}
