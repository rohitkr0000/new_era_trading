<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\PlanEngine;
use App\Services\WalletService;
use App\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo network for DEVELOPMENT only (never seed a live server - use `php artisan app:install`).
 * The demo passwords are NOT in the code: set DEMO_MEMBER_PASSWORD, DEMO_ADMIN_PASSWORD and DEMO_PIN in backend/.env.
 * Any that is missing is generated randomly and printed once at the end of the run.
 * Accounts: demo@mlm.com (member), admin@mlm.com (admin), <name>@example.com (team members, same password as the demo member).
 * Dates are back-dated with Carbon::setTestNow so the ROI history looks real.
 */
class DatabaseSeeder extends Seeder
{
    public function run(PlanEngine $plan, WalletService $wallet): void
    {
        $memberPw = env('DEMO_MEMBER_PASSWORD') ?: Str::password(14, symbols: false);
        $adminPw = env('DEMO_ADMIN_PASSWORD') ?: Str::password(14, symbols: false);
        $pin = env('DEMO_PIN') ?: (string) random_int(100000, 999999);
        Settings::flush();
        $real = Carbon::now();
        $start = $real->copy()->subDays(9);
        $at = function ($t) { Carbon::setTestNow($t); };

        $at($start);
        $root = $plan->register(['name' => 'Demo Leader', 'email' => 'demo@mlm.com', 'password' => $memberPw, 'phone' => '9876543210'], true);
        $root->forceFill(['country' => 'India', 'city' => 'Mumbai', 'usdt_address' => '0x' . str_repeat('1', 40), 'txn_pin' => Hash::make($pin)])->save();   // obviously fake sample address
        $plan->invest($root->id, 100);
        User::create(['name' => 'Administrator', 'email' => 'admin@mlm.com', 'password' => $adminPw, 'role' => 'admin', 'code' => 'ADMIN', 'joined_at' => $start]);

        // [name, sponsor index (-1 = demo leader), leg, joining amount]
        $team = [
            ['Aarav Mehta', -1, 'left', 50], ['Sara Khan', -1, 'right', 25], ['Liam Carter', 0, 'left', 10], ['Noor Ali', 0, 'right', 20],
            ['Emma Brown', 1, 'left', 50], ['Rohan Das', 1, 'right', 5], ['Maya Singh', 2, 'left', 15], ['Omar Farooq', 3, 'right', 10],
            ['Zoe Evans', 4, 'left', 100], ['Ivan Petrov', -1, 'left', 25], ['Priya Nair', 5, 'right', 5], ['Jack Wilson', 9, 'right', 40],
        ];
        $made = [];
        foreach ($team as $i => [$name, $sp, $leg, $amount]) {
            $at($start->copy()->addSeconds((int) (($i + 1) * 86400 * 5 / count($team))));
            $sponsor = $sp === -1 ? $root : $made[$sp];
            $u = $plan->register(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '@example.com', 'password' => $memberPw,
                'sponsorCode' => $sponsor->code, 'leg' => $leg, 'amount' => $amount, 'phone' => '98' . (10000000 + $i * 137531)]);
            $u->forceFill(['country' => 'India'])->save();
            $made[] = $u;
        }

        $at($start->copy()->addHours(132)); $plan->invest($made[0]->id, 50);          // retopups → 5% retopup income
        $at($start->copy()->addHours(144)); $plan->invest($made[1]->id, 25);

        $at($start->copy()->addHours(149));
        $wallet->requestDeposit($root, 200, '0x9f2c4be1a07d3c8e5b61f0a2d4c7e9b3815f6a0c2d4e6f8a1b3c5d7e9f0a1b2c');
        $wallet->processDeposit((int) DB::table('deposits')->max('id'), 'approved');
        $at($start->copy()->addHours(154)); $plan->purchase($root->fresh(), 100, 'purchase');
        $at($start->copy()->addHours(158)); $wallet->transferToMember($root->fresh(), $made[0]->code, 20);

        $at(null); $plan->accrue();                                                     // ROI up to now
        $at($real->copy()->subHours(36));
        $wallet->transferToPurchase($root->fresh(), 10);
        $wallet->requestWithdrawal($root->fresh(), 15, 'USDT BEP20', $root->usdt_address, true);
        $wallet->processWithdrawal((int) DB::table('withdrawals')->max('id'), 'approved');
        $at($real->copy()->subHours(7));
        $wallet->requestDeposit($root, 50, '0x5d8a1c3e7f9b2a4d6c8e0f1a3b5c7d9e1f2a4b6c8d0e2f4a6b8c0d2e4f6a8b0c');
        $wallet->requestWithdrawal($root->fresh(), 5, 'USDT BEP20', $root->usdt_address, true);

        $ticket = function ($subject, $msg, array $replies, $status) use ($root) {
            $id = DB::table('tickets')->insertGetId(['ref' => 'TMP-' . uniqid(), 'user_id' => $root->id, 'subject' => $subject, 'message' => $msg, 'status' => $status, 'created_at' => Carbon::now()]);
            DB::table('tickets')->where('id', $id)->update(['ref' => 'TKT-' . (1000 + $id)]);
            foreach ($replies as $r) DB::table('ticket_replies')->insert(['ticket_id' => $id, 'by' => 'admin', 'text' => $r, 'created_at' => Carbon::now()]);
        };
        $ticket('How is level income calculated?', 'Is the 12-level income paid on my team’s investment or on their ROI?',
            ['Level income is paid on every amount your downline invests: 3% (L1), 2% (L2), 1% (L3), 0.5% (L4) and 0.25% for levels 5–12. Level k unlocks with k active direct referrals.'], 'open');
        $ticket('Update my payout address', 'Please confirm that my BEP20 address is saved correctly.', ['Confirmed — your address is saved. Closing this ticket.'], 'closed');

        foreach ([[96, 'Welcome to New Era Trading — Trade Smart, Grow Together. Set your transaction PIN to secure payouts.'],
                  [168, 'Withdrawal window: Monday to Friday, 10 AM – 2 PM IST. Payouts are received within 24 hours.'],
                  [192, 'Saturday and Sunday are non-trading days: no ROI and no withdrawals on weekends.']] as [$h, $text]) {
            DB::table('announcements')->insert(['text' => $text, 'created_at' => $start->copy()->addHours($h)]);
        }

        $at(null);
        $plan->accrue();

        app(\App\Services\Snapshot::class)->refreshTeamStats();   // leaderboard numbers
        if (! env('DEMO_MEMBER_PASSWORD') || ! env('DEMO_ADMIN_PASSWORD') || ! env('DEMO_PIN')) {
            $this->command?->warn('Demo passwords were not set in .env, so random ones were generated (shown once):');
            $this->command?->line("  member demo@mlm.com / $memberPw  (PIN $pin)      admin admin@mlm.com / $adminPw");
        }
        // demo accounts count as e-mail verified and KYC approved so every screen can be explored
        DB::table('users')->update(['email_verified_at' => Carbon::now(), 'kyc_status' => 'approved']);
    }
}
