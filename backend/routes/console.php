<?php

use App\Services\PlanEngine;
use App\Services\WalletService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
 | Scheduled jobs. Production cron (one line):  * * * * * php /path/to/backend/artisan schedule:run >> /dev/null 2>&1
 | Local:                                        php artisan schedule:work
 |
 |   net:accrue          every 15 min  daily ROI (trading days only) — safe to run any time, only credits what is due
 |   net:verify-deposits every 5 min   re-check pending deposits on the blockchain (and auto-credit when enabled)
 |   net:stats           every minute team size / business per member (leaderboard) — keeps the O(members) work out of web requests
 |   net:risk-scan       every 10 min  looks for data errors and suspicious activity, raises admin alerts
 |   app:backup          daily 02:30   database + KYC documents backup
 */
Artisan::command('net:accrue', function (PlanEngine $plan) {
    $this->info($plan->accrue() . ' ROI credit(s) posted.');
    cache()->forever('net.scheduler.last', now()->toIso8601String());   // heartbeat read by app:check-production
})->purpose('Credit due ROI (trading days only) and level income');

Artisan::command('net:stats', function (\App\Services\Snapshot $snap) {
    $this->info($snap->refreshTeamStats() . ' member(s) had their team numbers refreshed.');
})->purpose('Refresh team size / business per member (leaderboard)');

Artisan::command('net:verify-deposits', function (WalletService $wallet) {
    if (! \App\Services\ChainVerifier::enabled()) { $this->line('On-chain check is off (no ETHERSCAN_API_KEY).'); return; }
    $ids = DB::table('deposits')->where('status', 'pending')->where(fn ($q) => $q->whereNull('verified')->orWhere('verified', 'unchecked'))
        ->where('created_at', '>', now()->subDays(3))->orderBy('id')->limit(50)->pluck('id');
    foreach ($ids as $id) { $r = $wallet->verifyDeposit($id); $this->line("deposit #$id: " . ($r['status'] ?? '-')); }
})->purpose('Re-check pending deposits on the blockchain');

Artisan::command('net:risk-scan', function (\App\Services\RiskService $risk) {
    $r = $risk->scan();
    $this->info("Risk scan done: {$r['new']} new alert(s), {$r['cleared']} cleared, {$r['open']} open.");
})->purpose('Look for data errors and suspicious activity and raise alerts for the admin');

Schedule::command('net:risk-scan')->everyTenMinutes()->withoutOverlapping();
Schedule::command('net:accrue')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('net:verify-deposits')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('net:stats')->everyMinute()->withoutOverlapping();
Schedule::command('app:backup')->dailyAt('02:30')->withoutOverlapping();
