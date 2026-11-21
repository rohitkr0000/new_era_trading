<?php

namespace App\Services;

use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Trading calendar in the company timezone:
 * ROI only on trading days (Mon–Fri), withdrawals only inside the window (10 AM – 2 PM).
 */
final class Market
{
    public static function isTradingDay(CarbonInterface $at): bool
    {
        $s = Settings::all();
        return in_array($at->copy()->setTimezone($s['timezone'])->dayOfWeek, $s['tradingDays'], true);
    }

    public static function hourLabel(int $h): string
    {
        return (($h % 12) ?: 12) . ':00 ' . ($h < 12 ? 'AM' : 'PM');
    }

    /** @return array{trading:bool, open:bool, reason:string, window:string} */
    public static function status(?CarbonInterface $at = null): array
    {
        $s = Settings::all();
        $local = ($at ?? Carbon::now())->copy()->setTimezone($s['timezone']);
        $mins = $local->hour * 60 + $local->minute;
        $trading = in_array($local->dayOfWeek, $s['tradingDays'], true);
        $open = $trading && $mins >= $s['withdrawStart'] * 60 && $mins < $s['withdrawEnd'] * 60;
        $start = self::hourLabel((int) $s['withdrawStart']);
        $end = self::hourLabel((int) $s['withdrawEnd']);
        $tz = $s['tzLabel'];

        $reason = match (true) {
            $open => "Withdrawal window is open until $end $tz.",
            ! $trading => "Weekend — trading is off and withdrawals are unavailable. Window reopens Monday $start $tz.",
            $mins < $s['withdrawStart'] * 60 => "Withdrawal window opens today at $start $tz.",
            default => "Today’s withdrawal window closed at $end $tz. Next window: next trading day $start.",
        };

        return ['trading' => $trading, 'open' => $open, 'reason' => $reason, 'window' => "$start – $end $tz"];
    }
}
