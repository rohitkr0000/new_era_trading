<?php

namespace App\Services;

use App\Models\User;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Watches the platform for two kinds of problems and raises an alert for the admin:
 *   · DATA ERRORS   — numbers that cannot be right (balance ≠ its ledger, income above the cap, ROI paid twice …)
 *   · SUSPICIOUS    — behaviour that often means fraud (many accounts on one IP, one payout wallet for many members,
 *                     repeated wrong PINs / passwords, a deposit that does not match the blockchain, P2P ping-pong …)
 *
 * Every rule is a set-based query (no per-member loop), so a scan is cheap even with many members.
 * A finding is identified by (member, rule, fingerprint): the same problem is raised once and stays `open` until an admin
 * resolves or dismisses it; if the cause disappears by itself the alert becomes `cleared`; a changed problem raises a new alert.
 */
class RiskService
{
    public const BIG_WITHDRAWAL = 1000;      // a pending withdrawal at or above this amount is flagged for a second look
    public const SAME_IP_MEMBERS = 3;        // this many different members on one IP address
    public const BAD_LOGINS_PER_HOUR = 5;
    public const BAD_PINS_PER_HOUR = 3;
    public const SIGNUPS_PER_DAY = 10;       // one sponsor getting this many sign-ups in 24 h

    public function __construct(private PlanEngine $plan) {}

    /* ------------------------------------------------------------------ scan (all members) */

    /** Runs every rule, stores new findings, clears the ones that are gone. @return array{new:int, open:int, cleared:int} */
    public function scan(): array
    {
        $found = $this->findings(null);
        $now = Carbon::now();
        $new = 0;
        $keys = [];
        foreach ($found as $f) {
            $keys[$f['user_id'] . '|' . $f['code'] . '|' . $f['fp']] = true;
            $n = DB::table('risk_alerts')->insertOrIgnore([
                'user_id' => $f['user_id'], 'code' => $f['code'], 'severity' => $f['severity'], 'title' => mb_substr($f['title'], 0, 160), 'detail' => $f['detail'],
                'data' => $f['data'] ? json_encode($f['data'], JSON_UNESCAPED_UNICODE) : null, 'fingerprint' => $f['fp'], 'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $new += $n;
        }
        $cleared = 0;
        foreach (DB::table('risk_alerts')->where('status', 'open')->get(['id', 'user_id', 'code', 'fingerprint']) as $a) {
            if (! isset($keys[$a->user_id . '|' . $a->code . '|' . $a->fingerprint])) {
                DB::table('risk_alerts')->where('id', $a->id)->update(['status' => 'cleared', 'handled_at' => $now, 'note' => 'The cause is gone (checked automatically).', 'updated_at' => $now]);
                $cleared++;
            }
        }
        cache()->forever('net.risk.last', $now->toIso8601String());
        return ['new' => $new, 'open' => DB::table('risk_alerts')->where('status', 'open')->count(), 'cleared' => $cleared];
    }

    /** Live check of ONE member (shown on the member's admin profile). @return array<int,array> */
    public function checkMember(int $id): array
    {
        return $this->findings($id);
    }

    /* ------------------------------------------------------------------ rules */

    /** @return array<int,array{user_id:int,code:string,severity:string,title:string,detail:string,fp:string,data:?array}> */
    private function findings(?int $only): array
    {
        $out = [];
        foreach ([
            'balances', 'negative', 'overCap', 'roiTwice', 'roiDays', 'sameIp', 'sameWallet', 'badLogins', 'badPins', 'depositMismatch',
            'bigWithdrawal', 'withdrawNoKyc', 'blockedPending', 'p2pPingPong', 'signupBurst',
        ] as $rule) {
            foreach ($this->$rule($only) as $f) $out[] = $f;
        }
        return $out;
    }

    private static function f(int $uid, string $code, string $sev, string $title, string $detail, string $fp, ?array $data = null): array
    {
        return ['user_id' => $uid, 'code' => $code, 'severity' => $sev, 'title' => $title, 'detail' => $detail, 'fp' => substr(md5($fp), 0, 32), 'data' => $data];
    }

    private function members(?int $only)
    {
        return DB::table('users')->where('role', 'member')->when($only, fn ($q) => $q->where('id', $only));
    }

    /** DATA ERROR: a wallet balance that is not the sum of its own ledger. */
    private function balances(?int $only): array
    {
        $out = [];
        $inc = DB::table('users as u')->leftJoin('ledger as l', 'l.user_id', '=', 'u.id')->where('u.role', 'member')->when($only, fn ($q) => $q->where('u.id', $only))
            ->groupBy('u.id', 'u.code', 'u.wallet')->selectRaw('u.id, u.code, u.wallet, COALESCE(SUM(l.amount),0) AS s')->get();
        foreach ($inc as $r) {
            if (abs((float) $r->wallet - (float) $r->s) > 0.009) {
                $d = Money::r(Money::sub($r->wallet, $r->s));
                $out[] = self::f($r->id, 'balance-mismatch', 'high', 'Income wallet does not match its history',
                    "{$r->code}: income wallet shows {$r->wallet} but the ledger adds up to " . Money::r($r->s) . " (difference $d).", "inc:$d", ['wallet' => $r->wallet, 'ledger' => Money::r($r->s)]);
            }
        }
        $pur = DB::table('users as u')->leftJoin('wallet_ledger as l', 'l.user_id', '=', 'u.id')->where('u.role', 'member')->when($only, fn ($q) => $q->where('u.id', $only))
            ->groupBy('u.id', 'u.code', 'u.purchase_wallet')->selectRaw('u.id, u.code, u.purchase_wallet, COALESCE(SUM(l.amount),0) AS s')->get();
        foreach ($pur as $r) {
            if (abs((float) $r->purchase_wallet - (float) $r->s) > 0.009) {
                $d = Money::r(Money::sub($r->purchase_wallet, $r->s));
                $out[] = self::f($r->id, 'balance-mismatch', 'high', 'Purchase wallet does not match its history',
                    "{$r->code}: purchase wallet shows {$r->purchase_wallet} but its ledger adds up to " . Money::r($r->s) . " (difference $d).", "pur:$d", ['wallet' => $r->purchase_wallet, 'ledger' => Money::r($r->s)]);
            }
        }
        return $out;
    }

    /** DATA ERROR: a negative balance. */
    private function negative(?int $only): array
    {
        $out = [];
        foreach ($this->members($only)->where(fn ($q) => $q->where('wallet', '<', 0)->orWhere('purchase_wallet', '<', 0))->get(['id', 'code', 'wallet', 'purchase_wallet']) as $u) {
            $out[] = self::f($u->id, 'negative-balance', 'high', 'Negative wallet balance', "{$u->code}: income wallet {$u->wallet}, purchase wallet {$u->purchase_wallet}.", "neg:{$u->wallet}:{$u->purchase_wallet}");
        }
        return $out;
    }

    /** DATA ERROR: income above the earning cap (the engine trims income to the cap, so this should never happen). */
    private function overCap(?int $only): array
    {
        $out = [];
        $s = Settings::all();
        $minMult = min((float) $s['capNonWorking'], (float) $s['capWorking']);
        if ($minMult <= 0) return [];
        $cand = DB::table('users as u')->join('ledger as l', 'l.user_id', '=', 'u.id')->where('u.role', 'member')->whereIn('l.type', PlanEngine::INCOME_TYPES)->when($only, fn ($q) => $q->where('u.id', $only))
            ->groupBy('u.id', 'u.invested')->havingRaw('SUM(l.amount) > u.invested * ?', [$minMult])->pluck('u.id');
        foreach (User::whereIn('id', $cand)->get() as $u) {
            $c = $this->plan->capInfo($u);
            if (! $c['unlimited'] && Money::cmp($c['used'], Money::add($c['cap'], '0.01')) > 0) {
                $over = Money::r(Money::sub($c['used'], $c['cap']));
                $out[] = self::f($u->id, 'over-cap', 'high', 'Income is above the earning cap', "{$u->code}: earned {$c['used']} but the {$c['mult']}X cap is {$c['cap']} (over by $over).", "cap:{$c['used']}:{$c['cap']}", ['earned' => $c['used'], 'cap' => $c['cap']]);
            }
        }
        return $out;
    }

    /** DATA ERROR: the same package credited ROI more than once on the same day. */
    private function roiTwice(?int $only): array
    {
        $out = [];
        $rows = DB::table('ledger')->where('type', 'roi')->whereNotNull('order_id')->when($only, fn ($q) => $q->where('user_id', $only))
            ->groupBy('user_id', 'order_id', DB::raw('DATE(created_at)'))->havingRaw('COUNT(*) > 1')->selectRaw('user_id, order_id, DATE(created_at) AS d, COUNT(*) AS n')->limit(200)->get();
        foreach ($rows as $r) {
            $out[] = self::f($r->user_id, 'roi-twice', 'high', 'ROI credited twice on one day', "Package #{$r->order_id} got {$r->n} ROI credits on {$r->d}.", "roi2:{$r->order_id}:{$r->d}");
        }
        return $out;
    }

    /** DATA ERROR: a package paid more ROI days than the plan allows. */
    private function roiDays(?int $only): array
    {
        $out = [];
        foreach (DB::table('orders')->where('days', '>', 0)->whereColumn('paid_days', '>', 'days')->when($only, fn ($q) => $q->where('user_id', $only))->limit(200)->get(['id', 'ref', 'user_id', 'days', 'paid_days']) as $o) {
            $out[] = self::f($o->user_id, 'roi-days', 'high', 'More ROI days paid than allowed', "Package {$o->ref} paid {$o->paid_days} days but the plan allows {$o->days}.", "rd:{$o->id}:{$o->paid_days}");
        }
        return $out;
    }

    /** SUSPICIOUS: several different members whose last login came from the same (public) IP address. */
    private function sameIp(?int $only): array
    {
        $out = [];
        $ips = DB::table('users')->where('role', 'member')->whereNotNull('last_login_ip')->groupBy('last_login_ip')->havingRaw('COUNT(*) >= ?', [self::SAME_IP_MEMBERS])->pluck('last_login_ip');
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) continue;   // office / home / local addresses are not evidence
            $m = DB::table('users')->where('role', 'member')->where('last_login_ip', $ip)->orderBy('id')->get(['id', 'code']);
            if ($only && ! $m->contains('id', $only)) continue;
            $codes = $m->pluck('code')->all();
            $out[] = self::f($only ?: $m->first()->id, 'same-ip', 'medium', 'Many members on one IP address', count($codes) . " members last signed in from $ip: " . implode(', ', array_slice($codes, 0, 12)) . (count($codes) > 12 ? ' …' : '') . '.', "ip:$ip:" . count($codes), ['ip' => $ip, 'members' => $codes]);
        }
        return $out;
    }

    /** SUSPICIOUS: one payout wallet saved by more than one member. */
    private function sameWallet(?int $only): array
    {
        $out = [];
        $addr = DB::table('users')->where('role', 'member')->whereNotNull('usdt_address')->where('usdt_address', '!=', '')->groupBy('usdt_address')->havingRaw('COUNT(*) > 1')->pluck('usdt_address');
        foreach ($addr as $a) {
            $m = DB::table('users')->where('role', 'member')->where('usdt_address', $a)->orderBy('id')->get(['id', 'code']);
            if ($only && ! $m->contains('id', $only)) continue;
            $codes = $m->pluck('code')->all();
            $out[] = self::f($only ?: $m->first()->id, 'same-wallet', 'medium', 'One payout wallet used by several members', count($codes) . ' members saved the same wallet ' . $a . ': ' . implode(', ', $codes) . '.', "w:$a:" . count($codes), ['wallet' => $a, 'members' => $codes]);
        }
        return $out;
    }

    /** SUSPICIOUS: repeated wrong passwords in the last hour (someone guessing the password). */
    private function badLogins(?int $only): array
    {
        $out = [];
        $rows = DB::table('audit_logs')->where('action', 'auth.login-failed')->whereNotNull('actor_id')->where('created_at', '>=', Carbon::now()->subHour())->when($only, fn ($q) => $q->where('actor_id', $only))
            ->groupBy('actor_id')->havingRaw('COUNT(*) >= ?', [self::BAD_LOGINS_PER_HOUR])->selectRaw('actor_id, COUNT(*) AS n')->get();
        foreach ($rows as $r) {
            $out[] = self::f($r->actor_id, 'bad-logins', 'medium', 'Repeated failed sign-ins', "{$r->n} failed sign-ins on this account in the last hour.", 'bl:' . Carbon::now()->format('Y-m-d-H'), ['count' => $r->n]);
        }
        return $out;
    }

    /** SUSPICIOUS: repeated wrong transaction PINs (someone with the session trying to guess the PIN). */
    private function badPins(?int $only): array
    {
        $out = [];
        $rows = DB::table('audit_logs')->where('action', 'security.wrong-pin')->whereNotNull('actor_id')->where('created_at', '>=', Carbon::now()->subHour())->when($only, fn ($q) => $q->where('actor_id', $only))
            ->groupBy('actor_id')->havingRaw('COUNT(*) >= ?', [self::BAD_PINS_PER_HOUR])->selectRaw('actor_id, COUNT(*) AS n')->get();
        foreach ($rows as $r) {
            $out[] = self::f($r->actor_id, 'bad-pins', 'high', 'Repeated wrong transaction PIN', "{$r->n} wrong PIN entries in the last hour. The PIN protects withdrawals and transfers.", 'bp:' . Carbon::now()->format('Y-m-d-H'), ['count' => $r->n]);
        }
        return $out;
    }

    /** SUSPICIOUS: a deposit whose hash does not match what the blockchain shows (wrong amount / wrong sender). */
    private function depositMismatch(?int $only): array
    {
        $out = [];
        foreach (DB::table('deposits')->where('verified', 'mismatch')->where('status', 'pending')->when($only, fn ($q) => $q->where('user_id', $only))->limit(200)->get() as $d) {
            $out[] = self::f($d->user_id, 'deposit-mismatch', 'high', 'Deposit does not match the blockchain', "Deposit #{$d->id} claims {$d->amount} USDT. " . ($d->verify_note ?: 'The blockchain check did not match.'), "dm:{$d->id}", ['deposit' => $d->id, 'amount' => $d->amount]);
        }
        return $out;
    }

    /** SUSPICIOUS: a large withdrawal waiting for approval. */
    private function bigWithdrawal(?int $only): array
    {
        $out = [];
        foreach (DB::table('withdrawals')->where('status', 'pending')->where('amount', '>=', self::BIG_WITHDRAWAL)->when($only, fn ($q) => $q->where('user_id', $only))->limit(200)->get() as $w) {
            $out[] = self::f($w->user_id, 'big-withdrawal', 'medium', 'Large withdrawal waiting', "Withdrawal {$w->ref} of {$w->amount} is pending approval.", "bw:{$w->id}", ['withdrawal' => $w->id]);
        }
        return $out;
    }

    /** DATA ERROR / BYPASS: a pending withdrawal from a member who has no approved KYC while KYC is required. */
    private function withdrawNoKyc(?int $only): array
    {
        if (empty(Settings::get('kycRequired'))) return [];
        $out = [];
        foreach (DB::table('withdrawals as w')->join('users as u', 'u.id', '=', 'w.user_id')->where('w.status', 'pending')->where('u.kyc_status', '!=', 'approved')->when($only, fn ($q) => $q->where('u.id', $only))
            ->limit(200)->get(['w.id', 'w.ref', 'w.user_id', 'w.amount', 'u.code']) as $w) {
            $out[] = self::f($w->user_id, 'no-kyc-withdrawal', 'high', 'Withdrawal without approved KYC', "{$w->code}: withdrawal {$w->ref} ({$w->amount}) is pending but the member's KYC is not approved.", "nk:{$w->id}");
        }
        return $out;
    }

    /** SUSPICIOUS: a blocked member that still has money waiting to be paid out. */
    private function blockedPending(?int $only): array
    {
        $out = [];
        foreach (DB::table('withdrawals as w')->join('users as u', 'u.id', '=', 'w.user_id')->where('w.status', 'pending')->where('u.blocked', true)->when($only, fn ($q) => $q->where('u.id', $only))
            ->limit(200)->get(['w.id', 'w.ref', 'w.user_id', 'w.amount', 'u.code']) as $w) {
            $out[] = self::f($w->user_id, 'blocked-pending', 'medium', 'Blocked member has a pending withdrawal', "{$w->code} is blocked but withdrawal {$w->ref} ({$w->amount}) is waiting. Decide whether to reject it.", "bp:{$w->id}");
        }
        return $out;
    }

    /** SUSPICIOUS: two members repeatedly sending money back and forth (moving funds around / fee or cap tricks). */
    private function p2pPingPong(?int $only): array
    {
        $out = [];
        $since = Carbon::now()->subDays(7);
        $rows = DB::table('transfers')->where('kind', 'member')->where('created_at', '>=', $since)
            ->selectRaw('CASE WHEN from_id < to_id THEN from_id ELSE to_id END AS a, CASE WHEN from_id < to_id THEN to_id ELSE from_id END AS b, COUNT(*) AS n, COUNT(DISTINCT from_id) AS dirs, SUM(amount) AS total')
            ->groupBy(DB::raw('CASE WHEN from_id < to_id THEN from_id ELSE to_id END'), DB::raw('CASE WHEN from_id < to_id THEN to_id ELSE from_id END'))
            ->havingRaw('COUNT(*) >= 4 AND COUNT(DISTINCT from_id) = 2')->get();
        foreach ($rows as $r) {
            if ($only && $only != $r->a && $only != $r->b) continue;
            $codes = DB::table('users')->whereIn('id', [$r->a, $r->b])->orderBy('id')->pluck('code')->all();
            $out[] = self::f($only ?: (int) $r->a, 'p2p-pingpong', 'medium', 'Money moving back and forth between two members', implode(' and ', $codes) . " exchanged {$r->n} transfers (total " . Money::r($r->total) . ') in the last 7 days, in both directions.', "pp:{$r->a}:{$r->b}:{$r->n}", ['members' => $codes]);
        }
        return $out;
    }

    /** SUSPICIOUS: one sponsor receiving many sign-ups in a day (bot or fake accounts). */
    private function signupBurst(?int $only): array
    {
        $out = [];
        $rows = DB::table('users')->where('role', 'member')->whereNotNull('sponsor_id')->where('joined_at', '>=', Carbon::now()->subDay())->when($only, fn ($q) => $q->where('sponsor_id', $only))
            ->groupBy('sponsor_id')->havingRaw('COUNT(*) >= ?', [self::SIGNUPS_PER_DAY])->selectRaw('sponsor_id, COUNT(*) AS n')->get();
        foreach ($rows as $r) {
            $code = DB::table('users')->where('id', $r->sponsor_id)->value('code');
            $out[] = self::f($r->sponsor_id, 'signup-burst', 'low', 'Many sign-ups under one sponsor in a day', "$code got {$r->n} new members in the last 24 hours. Check they are real people.", 'sb:' . Carbon::now()->format('Y-m-d') . ':' . $r->n);
        }
        return $out;
    }
}
