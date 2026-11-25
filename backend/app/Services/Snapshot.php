<?php

namespace App\Services;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Support\SafeCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Builds the JSON the front end works with (same shape the old localStorage `Store` used:
 * camelCase fields, numbers for money, millisecond timestamps).
 *
 *  · public()  — plan settings + headline stats + top leaders (no personal data)
 *  · member()  — the member's own records + their downline (public fields only)
 *  · admin()   — everything (admin panel)
 */
class Snapshot
{
    public function __construct(private PlanEngine $plan) {}

    public static function key(int $userId): string { return 'snap.m.' . $userId; }

    /** Drop a member's cached dashboard (called after their writes and whenever their money changes). */
    public static function forget(int $userId): void { SafeCache::forget(self::key($userId)); }

    /* ---------------- serializers ---------------- */

    public static function ms($dt): ?int
    {
        return $dt ? Carbon::parse($dt)->getTimestampMs() : null;
    }

    private static function num($v): float { return round((float) $v, 2); }

    public static function user($u, bool $private = false): array
    {
        $row = [
            'id' => $u->id, 'code' => $u->code, 'name' => $u->name, 'role' => $u->role, 'blocked' => (bool) $u->blocked,
            'phone' => $u->phone ?? '', 'invested' => self::num($u->invested), 'joined' => self::ms($u->joined_at ?? $u->created_at),
            'sponsorId' => $u->sponsor_id, 'parentId' => $u->parent_id, 'leg' => $u->leg, 'leftId' => $u->left_id, 'rightId' => $u->right_id,
            'leftBV' => self::num($u->left_bv), 'rightBV' => self::num($u->right_bv), 'wallet' => 0, 'purchaseWallet' => 0,
        ];
        if ($private) {
            $row += ['email' => $u->email, 'country' => $u->country ?? '', 'city' => $u->city ?? '', 'usdtAddress' => $u->usdt_address ?? '', 'hasPin' => (bool) $u->txn_pin,
                'emailVerified' => (bool) $u->email_verified_at, 'kyc' => $u->kyc_status ?: 'none', 'kycNote' => $u->kyc_note, 'lastLogin' => self::ms($u->last_login_at)];
            $row['wallet'] = self::num($u->wallet);
            $row['purchaseWallet'] = self::num($u->purchase_wallet);
        }
        return $row;
    }

    private static function order($o): array
    {
        return ['id' => $o->id, 'ref' => $o->ref, 'userId' => $o->user_id, 'amount' => self::num($o->amount), 'kind' => $o->kind, 'option' => $o->option,
            'pct' => (float) $o->pct, 'days' => (int) $o->days, 'steps' => (int) $o->steps, 'paidDays' => (int) $o->paid_days, 'status' => $o->status,
            'maturedBalance' => $o->matured_balance !== null ? self::num($o->matured_balance) : null, 'date' => self::ms($o->created_at)];
    }

    private static function tx($t): array
    {
        return ['id' => $t->id, 'userId' => $t->user_id, 'type' => $t->type, 'amount' => self::num($t->amount), 'note' => $t->note, 'fromId' => $t->from_id, 'date' => self::ms($t->created_at)];
    }

    private static function rows(string $table, callable $map, $query = null): array
    {
        return ($query ?? DB::table($table))->get()->map($map)->values()->all();
    }

    /* ---------------- tree helpers ---------------- */

    /**
     * Team size / business per member for the whole network, O(n) with no recursion.
     * A sponsor always registers before the people they sponsor (sponsor_id < id), so walking ids from high to low
     * adds every finished subtree into its sponsor exactly once.
     *
     * @param Collection<int, object> $members rows with id, sponsor_id, invested
     * @return array{directs: array<int,int>, size: array<int,int>, business: array<int,float>}
     */
    private function treeStats(Collection $members): array
    {
        $size = []; $business = []; $directs = [];
        foreach ($members as $m) { $size[$m->id] = 0; $business[$m->id] = 0.0; $directs[$m->id] = 0; }
        foreach ($members->sortByDesc('id') as $m) {
            if ($m->sponsor_id && isset($size[$m->sponsor_id])) {
                $size[$m->sponsor_id] += 1 + $size[$m->id];
                $business[$m->sponsor_id] += (float) $m->invested + $business[$m->id];
                $directs[$m->sponsor_id]++;
            }
        }
        return ['directs' => $directs, 'size' => $size, 'business' => array_map(fn ($b) => round($b, 2), $business)];
    }

    /**
     * The O(members) part, run by `php artisan net:stats` every minute (never inside a web request):
     * writes team_size / team_business into the users table (only the rows that changed).
     * @return int number of members whose numbers changed
     */
    public function refreshTeamStats(): int
    {
        $rows = DB::table('users')->where('role', 'member')->get(['id', 'sponsor_id', 'invested', 'team_size', 'team_business']);
        $t = $this->treeStats($rows);
        $changed = 0;
        foreach ($rows as $r) {
            $size = $t['size'][$r->id]; $biz = $t['business'][$r->id];
            if ((int) $r->team_size === $size && abs((float) $r->team_business - $biz) < 0.005) continue;
            DB::table('users')->where('id', $r->id)->update(['team_size' => $size, 'team_business' => $biz]);
            $changed++;
        }
        SafeCache::forget('net.snapshot.public');
        return $changed;
    }

    /**
     * Everything under a member (sponsor line + binary subtree), found inside the database with one recursive query,
     * so a normal member never makes the server load the whole network.
     * @return int[]
     */
    private function downlineIds(int $id): array
    {
        $rows = DB::select(
            'WITH RECURSIVE sp(id) AS (SELECT id FROM users WHERE sponsor_id = ? UNION SELECT u.id FROM users u JOIN sp ON u.sponsor_id = sp.id),
                            bt(id) AS (SELECT id FROM users WHERE parent_id = ? UNION SELECT u.id FROM users u JOIN bt ON u.parent_id = bt.id)
             SELECT id FROM sp UNION SELECT id FROM bt', [$id, $id]);
        return array_map(fn ($r) => (int) $r->id, $rows);
    }

    /**
     * Numbers every page shows (member count, headline stats, leaders, newest members …). A few cheap SQL queries,
     * cached for SNAPSHOT_CACHE seconds, so it does not matter how many people are online.
     */
    private function publicNumbers(): array
    {
        return SafeCache::remember('net.snapshot.public', (int) config('app.snapshot_cache', 60), function () {
            $members = DB::table('users')->where('role', 'member');
            $count = (clone $members)->count();
            $leaders = (clone $members)->orderByDesc('team_size')->orderByDesc('team_business')->limit(4)->get(['name', 'code', 'team_size'])
                ->map(fn ($m) => ['name' => $m->name, 'code' => $m->code, 'team' => (int) $m->team_size])->all();
            $latest = (clone $members)->orderByDesc('joined_at')->limit(5)->get(['id', 'name', 'joined_at', 'invested'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'joined' => self::ms($u->joined_at), 'invested' => self::num($u->invested)])->all();
            $top = (clone $members)->orderByDesc('team_size')->limit(5)->get(['id', 'name', 'team_size']);
            $directs = DB::table('users')->whereIn('sponsor_id', $top->pluck('id')->all() ?: [0])->selectRaw('sponsor_id, count(*) n')->groupBy('sponsor_id')->pluck('n', 'sponsor_id');
            $builders = $top->map(fn ($u) => ['user' => ['id' => $u->id, 'name' => $u->name], 'team' => (int) $u->team_size, 'directs' => (int) ($directs[$u->id] ?? 0)])->all();

            $recent = DB::table('orders')->orderByDesc('created_at')->limit(5)->get();
            $names = DB::table('users')->whereIn('id', $recent->pluck('user_id')->all() ?: [0])->pluck('name', 'id');
            $upgrades = $recent->map(fn ($o) => ['userId' => $o->user_id, 'user' => ['name' => $names[$o->user_id] ?? 'Member'], 'kind' => $o->kind, 'amount' => self::num($o->amount), 'date' => self::ms($o->created_at)])->all();

            return [
                'count' => $count,
                'stats' => [
                    'members' => $count,
                    'active' => (clone $members)->where('blocked', false)->where('invested', '>', 0)->count(),
                    'orders' => DB::table('orders')->count(),
                    'paid' => round((float) DB::table('ledger')->whereIn('type', PlanEngine::INCOME_TYPES)->sum('amount'), 2),
                ],
                'leaders' => $leaders, 'latest' => $latest, 'builders' => $builders, 'upgrades' => $upgrades,
            ];
        });
    }

    /* ---------------- snapshots ---------------- */

    /** Columns a member may see of the people in their team (never e-mail, password, PIN, balances of others). */
    private const TEAM_COLUMNS = ['id', 'code', 'name', 'role', 'blocked', 'phone', 'invested', 'joined_at', 'created_at', 'sponsor_id', 'parent_id', 'leg', 'left_id', 'right_id', 'left_bv', 'right_bv'];

    public function public(): array
    {
        $n = $this->publicNumbers();
        return [
            'mode' => 'public',
            'demo' => (bool) config('app.demo_mode'),
            'demoLogins' => config('app.demo_mode') ? config('app.demo_logins') : null,
            'settings' => Settings::all(),
            'extras' => ['stats' => $n['stats'], 'leaders' => $n['leaders']],
        ];
    }

    public function member(User $me): array
    {
        $n = $this->publicNumbers();
        $ids = $this->downlineIds($me->id);
        $related = array_values(array_unique(array_filter(array_merge($ids, [$me->sponsor_id, $me->parent_id]))));
        $users = [];
        foreach (array_chunk($related, 1000) as $chunk) {
            foreach (DB::table('users')->whereIn('id', $chunk)->get(self::TEAM_COLUMNS) as $u) $users[] = self::user($u);
        }
        $users[] = self::user($me, true);

        // leaderboard position = how many members have a bigger team / more business (two indexed COUNT queries)
        $rank = fn (string $col, $mine) => 1 + DB::table('users')->where('role', 'member')->where($col, '>', $mine)->count();

        $tr = DB::table('transfers')->where('from_id', $me->id)->orWhere('to_id', $me->id)->get();
        $who = DB::table('users')->whereIn('id', $tr->pluck('from_id')->merge($tr->pluck('to_id'))->unique()->values()->all() ?: [0])->get(['id', 'name', 'code'])->keyBy('id');
        $transfers = $tr->map(function ($x) use ($who) {
            $f = $who[$x->from_id] ?? null; $to = $who[$x->to_id] ?? null;
            return ['id' => $x->id, 'kind' => $x->kind, 'fromId' => $x->from_id, 'toId' => $x->to_id, 'amount' => self::num($x->amount), 'fee' => self::num($x->fee ?? 0), 'net' => self::num($x->net ?? $x->amount), 'date' => self::ms($x->created_at),
                'fromName' => $f?->name, 'fromCode' => $f?->code, 'toName' => $to?->name, 'toCode' => $to?->code];
        })->all();

        $orders = [];
        foreach (array_chunk(array_merge($ids, [$me->id]), 1000) as $chunk) {
            foreach (DB::table('orders')->whereIn('user_id', $chunk)->get() as $o) $orders[] = self::order($o);
        }

        return [
            'mode' => 'member',
            'demoLogins' => config('app.demo_mode') ? config('app.demo_logins') : null,
            'me' => ['id' => $me->id, 'role' => 'member', 'hasPin' => (bool) $me->txn_pin, 'emailVerified' => (bool) $me->email_verified_at, 'kyc' => $me->kyc_status ?: 'none', 'kycNote' => $me->kyc_note,
                'compoundMode' => $me->compound_mode ?: 'compound'],
            'demo' => (bool) config('app.demo_mode'),
            'settings' => Settings::all(),
            'db' => [
                'users' => $users,
                'orders' => $orders,
                'tx' => self::rows('ledger', fn ($r) => self::tx($r), DB::table('ledger')->where('user_id', $me->id)),
                'wtx' => self::rows('wallet_ledger', fn ($r) => ['id' => $r->id, 'userId' => $r->user_id, 'type' => $r->type, 'amount' => self::num($r->amount), 'note' => $r->note, 'balance' => self::num($r->balance), 'date' => self::ms($r->created_at)],
                    DB::table('wallet_ledger')->where('user_id', $me->id)),
                'deposits' => $this->depositRows(DB::table('deposits')->where('user_id', $me->id)),
                'withdrawals' => $this->withdrawalRows(DB::table('withdrawals')->where('user_id', $me->id)),
                'deductions' => self::rows('deductions', fn ($r) => ['id' => $r->id, 'userId' => $r->user_id, 'wid' => $r->withdrawal_id, 'note' => $r->note, 'amount' => self::num($r->amount), 'status' => $r->status, 'date' => self::ms($r->created_at)],
                    DB::table('deductions')->where('user_id', $me->id)),
                'transfers' => $transfers,
                'tickets' => $this->ticketRows(DB::table('tickets')->where('user_id', $me->id)),
                'announcements' => $this->announcementRows(),
                'compound' => $this->compoundRows($me),
            ],
            'extras' => [
                'positions' => ['of' => $n['count'], 'team' => ['pos' => $rank('team_size', $me->team_size), 'value' => (int) $me->team_size], 'business' => ['pos' => $rank('team_business', $me->team_business), 'value' => self::num($me->team_business)]],
                'latestMembers' => $n['latest'], 'topBuilders' => $n['builders'], 'recentUpgrades' => $n['upgrades'],
                'market' => Market::status(),
            ],
        ];
    }

    public function admin(User $admin): array
    {
        $users = User::orderBy('id')->get()->map(fn ($u) => array_merge(self::user($u, true), ['email' => $u->email]))->all();
        return [
            'mode' => 'admin',
            'me' => ['id' => $admin->id, 'role' => 'admin', 'hasPin' => false],
            'demo' => (bool) config('app.demo_mode'),
            'settings' => Settings::all(),
            'db' => [
                'users' => $users,
                'orders' => self::rows('orders', fn ($o) => self::order($o)),
                'tx' => self::rows('ledger', fn ($r) => self::tx($r)),
                'wtx' => [], 'transfers' => [],
                'deductions' => self::rows('deductions', fn ($r) => ['id' => $r->id, 'userId' => $r->user_id, 'wid' => $r->withdrawal_id, 'note' => $r->note, 'amount' => self::num($r->amount), 'status' => $r->status, 'date' => self::ms($r->created_at)]),
                'deposits' => $this->depositRows(DB::table('deposits')),
                'withdrawals' => $this->withdrawalRows(DB::table('withdrawals')),
                'tickets' => $this->ticketRows(DB::table('tickets')),
                'announcements' => $this->announcementRows(),
            ],
            'extras' => ['market' => Market::status()],
        ];
    }

    /* ---------------- shared row builders ---------------- */

    private function depositRows($q): array
    {
        return $q->get()->map(fn ($d) => ['id' => $d->id, 'userId' => $d->user_id, 'amount' => self::num($d->amount), 'txnId' => $d->txn_id, 'network' => $d->network,
            'status' => $d->status, 'date' => self::ms($d->created_at), 'processedAt' => self::ms($d->processed_at),
            'verified' => $d->verified, 'verifyNote' => $d->verify_note, 'chainAmount' => $d->chain_amount === null ? null : self::num($d->chain_amount)])->all();
    }

    private function withdrawalRows($q): array
    {
        return $q->get()->map(fn ($w) => ['id' => $w->id, 'ref' => $w->ref, 'userId' => $w->user_id, 'amount' => self::num($w->amount), 'fee' => self::num($w->fee), 'net' => self::num($w->net),
            'method' => $w->method, 'account' => $w->account, 'status' => $w->status, 'date' => self::ms($w->created_at), 'due' => self::ms($w->due_at), 'processedAt' => self::ms($w->processed_at),
            'payoutTxid' => $w->payout_txid, 'adminNote' => $w->admin_note])->all();
    }

    private function ticketRows($q): array
    {
        $tickets = $q->get();
        $replies = DB::table('ticket_replies')->whereIn('ticket_id', $tickets->pluck('id'))->orderBy('id')->get()->groupBy('ticket_id');
        return $tickets->map(fn ($t) => ['id' => $t->id, 'ref' => $t->ref, 'userId' => $t->user_id, 'subject' => $t->subject, 'message' => $t->message, 'status' => $t->status,
            'date' => self::ms($t->created_at), 'replies' => ($replies[$t->id] ?? collect())->map(fn ($r) => ['by' => $r->by, 'text' => $r->text, 'date' => self::ms($r->created_at)])->values()->all()])->all();
    }

    /** The member's compounding balance: live lots, each compounding package's own state, and the daily schedule (newest 90 days). */
    private function compoundRows(User $me): array
    {
        $lots = DB::table('compound_lots')->where('user_id', $me->id)->where('days_left', '>', 0)->orderBy('id')->get();
        $sched = DB::table('compound_ledger')->where('user_id', $me->id)->orderByDesc('day')->limit(90)->get();
        $orders = DB::table('orders')->where('user_id', $me->id)->where('option', 'Compounding')->orderByDesc('id')->get();
        $liveByOrder = $lots->groupBy(fn ($l) => (string) $l->order_id);
        return [
            'mode' => $me->compound_mode ?: 'compound',
            'balance' => self::num($lots->sum('amount') + $orders->whereNotNull('matured_balance')->sum('matured_balance')),
            'lots' => $lots->map(fn ($l) => ['id' => $l->id, 'orderId' => $l->order_id, 'amount' => self::num($l->amount), 'principal' => (bool) $l->is_principal, 'daysLeft' => (int) $l->days_left, 'since' => $l->created_on])->values()->all(),
            'schedule' => $sched->map(fn ($r) => ['day' => $r->day, 'mode' => $r->mode, 'opening' => self::num($r->opening), 'interest' => self::num($r->interest), 'expiring' => self::num($r->expiring), 'closing' => self::num($r->closing)])->values()->all(),
            'packages' => $orders->map(fn ($o) => ['orderId' => $o->id, 'ref' => $o->ref, 'amount' => self::num($o->amount), 'status' => $o->status,
                'paidDays' => (int) $o->paid_days, 'days' => (int) $o->days, 'date' => self::ms($o->created_at),
                'value' => $o->status === 'matured' ? self::num($o->matured_balance) : self::num(($liveByOrder[(string) $o->id] ?? collect())->sum('amount'))])->values()->all(),
        ];
    }

    private function announcementRows(): array
    {
        return DB::table('announcements')->orderByDesc('created_at')->get()->map(fn ($a) => ['id' => $a->id, 'text' => $a->text, 'date' => self::ms($a->created_at)])->all();
    }
}
