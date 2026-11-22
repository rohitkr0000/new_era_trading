<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PlanEngine;
use App\Services\RiskService;
use App\Services\Snapshot;
use App\Support\Audit;
use App\Support\Money;
use App\Support\Phone;
use App\Support\PlanException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/** Admin: one member's full picture (team, income, money, activity) and the suspicious-activity alerts. */
class RiskController extends Controller
{
    public function __construct(private PlanEngine $plan, private RiskService $risk) {}

    private function admin(Request $request): User
    {
        $u = $request->user();
        if (! $u->isAdmin()) abort(403, 'Admins only.');
        return $u;
    }

    private static function n($v): float { return round((float) $v, 2); }

    /* ------------------------------------------------------------------ alerts */

    public function alerts(Request $request): JsonResponse
    {
        $this->admin($request);
        $status = (string) $request->query('status', 'open');
        $q = DB::table('risk_alerts as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->orderByRaw("CASE a.severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")->orderByDesc('a.id');
        if (in_array($status, ['open', 'resolved', 'dismissed', 'cleared'], true)) $q->where('a.status', $status);
        if (in_array($sev = (string) $request->query('severity'), ['high', 'medium', 'low'], true)) $q->where('a.severity', $sev);
        $rows = $q->limit(min(300, max(1, (int) $request->query('limit', 100))))->get(['a.*', 'u.name as user_name', 'u.code as user_code', 'u.blocked as user_blocked']);
        $open = DB::table('risk_alerts')->where('status', 'open')->selectRaw('severity, COUNT(*) AS n')->groupBy('severity')->pluck('n', 'severity');
        return response()->json([
            'items' => $rows->map(fn ($a) => [
                'id' => $a->id, 'userId' => $a->user_id, 'userName' => $a->user_name, 'userCode' => $a->user_code, 'blocked' => (bool) $a->user_blocked, 'code' => $a->code, 'severity' => $a->severity,
                'title' => $a->title, 'detail' => $a->detail, 'data' => $a->data ? json_decode($a->data, true) : null, 'status' => $a->status, 'note' => $a->note,
                'date' => Snapshot::ms($a->created_at), 'handledAt' => Snapshot::ms($a->handled_at),
            ])->values(),
            'counts' => ['high' => (int) ($open['high'] ?? 0), 'medium' => (int) ($open['medium'] ?? 0), 'low' => (int) ($open['low'] ?? 0), 'open' => (int) $open->sum()],
            'lastScan' => cache()->get('net.risk.last'),
        ]);
    }

    public function alertUpdate(Request $request, int $id): JsonResponse
    {
        $admin = $this->admin($request);
        $d = $request->validate(['status' => 'required|in:open,resolved,dismissed', 'note' => 'nullable|string|max:255']);
        $a = DB::table('risk_alerts')->where('id', $id)->first();
        abort_unless($a, 404, 'Alert not found.');
        DB::table('risk_alerts')->where('id', $id)->update(['status' => $d['status'], 'note' => $d['note'] ?? $a->note, 'handled_by' => $d['status'] === 'open' ? null : $admin->id,
            'handled_at' => $d['status'] === 'open' ? null : Carbon::now(), 'updated_at' => Carbon::now()]);
        Audit::log('risk.' . $d['status'], $admin, 'alert#' . $id, ['rule' => $a->code, 'user' => $a->user_id, 'note' => $d['note'] ?? null]);
        return response()->json(['ok' => true]);
    }

    public function scan(Request $request): JsonResponse
    {
        $this->admin($request);
        return response()->json(['ok' => true] + $this->risk->scan());
    }

    /** Sign the member out everywhere (their tokens are deleted); they can log in again with their password. */
    public function revoke(Request $request, int $id): JsonResponse
    {
        $admin = $this->admin($request);
        $u = User::where('role', 'member')->findOrFail($id);
        $n = $u->tokens()->count();
        $u->tokens()->delete();
        Audit::log('member.sessions-revoked', $admin, 'user#' . $u->id, ['code' => $u->code, 'sessions' => $n]);
        return response()->json(['ok' => true, 'revoked' => $n]);
    }

    /* ------------------------------------------------------------------ admin edits a member's account */

    /** Change a member's e-mail and/or phone. Changing the e-mail makes it unverified again (they must re-verify). */
    public function updateContact(Request $request, int $id): JsonResponse
    {
        $admin = $this->admin($request);
        $u = User::where('role', 'member')->findOrFail($id);
        $d = $request->validate([
            'email' => 'nullable|string|email|max:160', 'phone' => 'nullable|string|max:30',
            'country' => 'nullable|string|max:60', 'city' => 'nullable|string|max:80', 'usdtAddress' => 'nullable|string|max:120',
        ]);
        if (! array_filter($d, fn ($v) => $v !== null && $v !== '')) throw new PlanException('Change at least one field first.');

        $changes = [];
        if (! empty($d['email'])) {
            $email = strtolower(trim($d['email']));
            if ($email !== $u->email) {
                if (User::where('email', $email)->where('id', '!=', $u->id)->exists()) throw new PlanException('This e-mail is already used by another account.');
                $changes['fromEmail'] = $u->email;
                $changes['toEmail'] = $email;
                $u->email = $email;
                $u->email_verified_at = null;   // an admin-set address is not proven yet — the member must verify it again
            }
        }
        if (! empty($d['phone'])) {
            $phone = trim($d['phone']);
            $key = Phone::key($phone);
            if ($key === null) throw new PlanException('Enter a valid mobile number (8–15 digits).');
            if ($key !== $u->phone_key) {
                if (User::where('phone_key', $key)->where('id', '!=', $u->id)->exists()) throw new PlanException('This mobile number is already registered.');
                $changes['fromPhone'] = $u->phone;
                $changes['toPhone'] = $phone;
                $u->phone = $phone;
                $u->phone_key = $key;
            }
        }
        foreach (['country' => 'country', 'city' => 'city', 'usdtAddress' => 'usdt_address'] as $in => $col) {
            if (array_key_exists($in, $d) && $d[$in] !== null && trim((string) $d[$in]) !== (string) $u->$col) {
                $changes[$col . 'Changed'] = true;
                $u->$col = trim($d[$in]) ?: null;
            }
        }
        if ($changes) {
            $u->save();
            Snapshot::forget($u->id);
            Audit::log('admin.member-contact-update', $admin, 'user#' . $u->id, $changes);
        }
        return response()->json(['ok' => true, 'emailVerified' => (bool) $u->email_verified_at]);
    }

    /** Set a member's password directly (support request, lost access …). Leave "password" empty to generate a strong one, shown once. Signs them out everywhere. */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $admin = $this->admin($request);
        $u = User::where('role', 'member')->findOrFail($id);
        $d = $request->validate(['password' => ['nullable', 'string', 'max:100', Password::min(8)->letters()->numbers()]]);
        $generated = empty($d['password']);
        $plain = $generated ? Str::password(14, symbols: false) : $d['password'];
        $u->password = $plain;   // the User model's 'hashed' cast bcrypts this on save
        $u->save();
        $revoked = $u->tokens()->count();
        $u->tokens()->delete();   // sign out of every device: they must sign in again with the new password
        Audit::log('admin.member-password-reset', $admin, 'user#' . $u->id, ['generated' => $generated, 'sessionsRevoked' => $revoked]);
        return response()->json(['ok' => true, 'password' => $generated ? $plain : null, 'sessionsRevoked' => $revoked]);
    }

    /** Set/replace a member's transaction PIN (lost PIN, support request). Leave "pin" empty to generate one, shown once. Also clears a wrong-PIN lockout. */
    public function resetPin(Request $request, int $id): JsonResponse
    {
        $admin = $this->admin($request);
        $u = User::where('role', 'member')->findOrFail($id);
        $d = $request->validate(['pin' => ['nullable', 'regex:/^\d{4,6}$/']], ['pin.regex' => 'PIN must be 4 to 6 digits.']);
        $generated = empty($d['pin']);
        $plain = $generated ? (string) random_int(100000, 999999) : $d['pin'];
        $u->txn_pin = Hash::make($plain);
        $u->save();
        RateLimiter::clear('pin:' . $u->id);   // a fresh PIN should not inherit an old wrong-PIN lockout
        Audit::log('admin.member-pin-reset', $admin, 'user#' . $u->id, ['generated' => $generated]);
        return response()->json(['ok' => true, 'pin' => $generated ? $plain : null]);
    }

    /**
     * Change who a member's future direct/retopup/level income flows up to. Commissions already paid under the old
     * sponsor are NOT rewritten (only new investments follow the new line), and this does not move the member in the
     * (cosmetic, income-free) Binary Tree — only sponsor_id changes. Refuses a cycle (the new sponsor must not already
     * be in this member's own downline).
     */
    public function updateSponsor(Request $request, int $id): JsonResponse
    {
        $admin = $this->admin($request);
        $u = User::where('role', 'member')->findOrFail($id);
        $d = $request->validate(['sponsorCode' => 'required|string|max:20']);
        $code = strtoupper(trim($d['sponsorCode']));
        $sponsor = User::where('code', $code)->where('role', 'member')->first();
        if (! $sponsor) throw new PlanException('Sponsor code not found.');
        if ($sponsor->id === $u->id) throw new PlanException('A member cannot sponsor themself.');
        if ($sponsor->id === $u->sponsor_id) throw new PlanException('That is already their sponsor.');
        $down = DB::select('WITH RECURSIVE sp(id) AS (SELECT id FROM users WHERE sponsor_id = ? UNION SELECT u.id FROM users u JOIN sp ON u.sponsor_id = sp.id) SELECT id FROM sp', [$u->id]);
        if (in_array((int) $sponsor->id, array_map(fn ($r) => (int) $r->id, $down), true)) throw new PlanException('That member is already inside this member’s own downline — choose someone outside it.');
        $old = $u->sponsor_id ? User::find($u->sponsor_id) : null;
        $u->sponsor_id = $sponsor->id;
        $u->save();
        Snapshot::forget($u->id);
        Audit::log('admin.member-sponsor-change', $admin, 'user#' . $u->id, ['fromSponsor' => $old?->code, 'toSponsor' => $sponsor->code]);
        return response()->json(['ok' => true, 'sponsor' => ['id' => $sponsor->id, 'name' => $sponsor->name, 'code' => $sponsor->code]]);
    }

    /* ------------------------------------------------------------------ one member */

    public function member(Request $request, int $id): JsonResponse
    {
        $this->admin($request);
        $u = User::where('role', 'member')->findOrFail($id);
        $sponsor = $u->sponsor_id ? DB::table('users')->where('id', $u->sponsor_id)->first(['id', 'name', 'code']) : null;

        // income by type
        $inc = DB::table('ledger')->where('user_id', $id)->selectRaw('type, SUM(amount) AS s, COUNT(*) AS n')->groupBy('type')->get()->keyBy('type');
        $sum = fn (string $t) => self::n($inc[$t]->s ?? 0);
        $income = ['roi' => $sum('roi'), 'direct' => $sum('direct'), 'retopup' => $sum('retopup'), 'level' => $sum('level')];
        $income['total'] = self::n(array_sum($income));
        $cap = $this->plan->capInfo($u);

        // team by level (sponsor line, level 1..12) and the whole team (any depth)
        $lv = DB::select('WITH RECURSIVE sp(id, lvl) AS (SELECT id, 1 FROM users WHERE sponsor_id = ? UNION ALL SELECT x.id, sp.lvl + 1 FROM users x JOIN sp ON x.sponsor_id = sp.id WHERE sp.lvl < 12)
            SELECT sp.lvl AS lvl, COUNT(*) AS n, SUM(CASE WHEN x.invested > 0 AND x.blocked = 0 THEN 1 ELSE 0 END) AS act, COALESCE(SUM(x.invested), 0) AS biz
            FROM sp JOIN users x ON x.id = sp.id GROUP BY sp.lvl ORDER BY sp.lvl', [$id]);
        $lvIncome = collect(DB::select("WITH RECURSIVE sp(id, lvl) AS (SELECT id, 1 FROM users WHERE sponsor_id = ? UNION ALL SELECT x.id, sp.lvl + 1 FROM users x JOIN sp ON x.sponsor_id = sp.id WHERE sp.lvl < 12)
            SELECT sp.lvl AS lvl, SUM(l.amount) AS s FROM ledger l JOIN sp ON sp.id = l.from_id WHERE l.user_id = ? AND l.type = 'level' GROUP BY sp.lvl", [$id, $id]))->pluck('s', 'lvl');
        $all = DB::select('WITH RECURSIVE sp(id) AS (SELECT id FROM users WHERE sponsor_id = ? UNION SELECT x.id FROM users x JOIN sp ON x.sponsor_id = sp.id)
            SELECT COUNT(*) AS n, SUM(CASE WHEN x.invested > 0 AND x.blocked = 0 THEN 1 ELSE 0 END) AS act, COALESCE(SUM(x.invested), 0) AS biz FROM sp JOIN users x ON x.id = sp.id', [$id])[0];
        $byLvl = collect($lv)->keyBy('lvl');
        $levels = [];
        $pcts = (array) (\App\Support\Settings::get('levelPct') ?? []);
        for ($i = 1; $i <= 12; $i++) {
            $r = $byLvl[$i] ?? null;
            $levels[] = ['level' => $i, 'pct' => (float) ($pcts[$i - 1] ?? 0), 'members' => (int) ($r->n ?? 0), 'active' => (int) ($r->act ?? 0), 'business' => self::n($r->biz ?? 0), 'income' => self::n($lvIncome[$i] ?? 0),
                'unlocked' => $cap['directs'] >= $i || ! \App\Support\Settings::get('levelUnlockPerLevel')];
        }
        $directs = DB::table('users')->where('sponsor_id', $id)->count();

        // money
        $deps = DB::table('deposits')->where('user_id', $id)->selectRaw('status, SUM(amount) AS s, COUNT(*) AS n')->groupBy('status')->get()->keyBy('status');
        $wds = DB::table('withdrawals')->where('user_id', $id)->selectRaw('status, SUM(amount) AS s, SUM(net) AS net, COUNT(*) AS n')->groupBy('status')->get()->keyBy('status');
        $p2pOut = DB::table('transfers')->where('kind', 'member')->where('from_id', $id)->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount),0) AS s')->first();
        $p2pIn = DB::table('transfers')->where('kind', 'member')->where('to_id', $id)->selectRaw('COUNT(*) AS n, COALESCE(SUM(net),0) AS s')->first();

        $names = fn ($ids) => DB::table('users')->whereIn('id', $ids ?: [0])->pluck('code', 'id');
        $transfers = DB::table('transfers')->where(fn ($q) => $q->where('from_id', $id)->orWhere('to_id', $id))->orderByDesc('id')->limit(15)->get();
        $codes = $names($transfers->pluck('from_id')->merge($transfers->pluck('to_id'))->unique()->values()->all());

        $audit = DB::table('audit_logs')->where(fn ($q) => $q->where('actor_id', $id)->orWhere('target', 'user#' . $id))->orderByDesc('id')->limit(25)->get();

        return response()->json([
            'member' => [
                'id' => $u->id, 'code' => $u->code, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'country' => $u->country, 'city' => $u->city, 'usdtAddress' => $u->usdt_address,
                'blocked' => (bool) $u->blocked, 'emailVerified' => (bool) $u->email_verified_at, 'hasPin' => (bool) $u->txn_pin, 'kyc' => $u->kyc_status ?: 'none',
                'joined' => Snapshot::ms($u->joined_at), 'lastLogin' => Snapshot::ms($u->last_login_at), 'lastLoginIp' => $u->last_login_ip, 'compoundMode' => $u->compound_mode,
                'sponsor' => $sponsor ? ['id' => $sponsor->id, 'name' => $sponsor->name, 'code' => $sponsor->code] : null,
                'invested' => self::n($u->invested), 'wallet' => self::n($u->wallet), 'purchaseWallet' => self::n($u->purchase_wallet), 'sessions' => $u->tokens()->count(),
            ],
            'income' => $income,
            'cap' => ['cap' => self::n($cap['cap']), 'used' => self::n($cap['used']), 'left' => self::n($cap['left']), 'pct' => $cap['pct'], 'mult' => $cap['mult'], 'working' => $cap['working'],
                'unlimited' => $cap['unlimited'], 'directs' => $cap['directs'], 'directBusiness' => self::n($cap['directBusiness']), 'businessNeeded' => self::n($cap['businessNeeded'])],
            'team' => ['directs' => $directs, 'activeDirects' => $cap['directs'], 'total' => (int) $all->n, 'active' => (int) $all->act, 'business' => self::n($all->biz), 'levels' => $levels],
            'money' => [
                'depositsApproved' => self::n($deps['approved']->s ?? 0), 'depositsPending' => self::n($deps['pending']->s ?? 0), 'depositsRejected' => self::n($deps['rejected']->s ?? 0),
                'withdrawnPaid' => self::n($wds['approved']->s ?? 0), 'withdrawnNet' => self::n($wds['approved']->net ?? 0), 'withdrawalsPending' => self::n($wds['pending']->s ?? 0), 'withdrawalsRejected' => self::n($wds['rejected']->s ?? 0),
                'p2pSent' => self::n($p2pOut->s), 'p2pSentCount' => (int) $p2pOut->n, 'p2pReceived' => self::n($p2pIn->s), 'p2pReceivedCount' => (int) $p2pIn->n,
            ],
            'orders' => DB::table('orders')->where('user_id', $id)->orderByDesc('id')->limit(15)->get()->map(fn ($o) => ['ref' => $o->ref, 'kind' => $o->kind, 'option' => $o->option, 'amount' => self::n($o->amount), 'paidDays' => (int) $o->paid_days, 'days' => (int) $o->days, 'status' => $o->status, 'date' => Snapshot::ms($o->created_at)])->values(),
            'deposits' => DB::table('deposits')->where('user_id', $id)->orderByDesc('id')->limit(10)->get()->map(fn ($d) => ['id' => $d->id, 'amount' => self::n($d->amount), 'status' => $d->status, 'verified' => $d->verified, 'note' => $d->verify_note, 'txn' => $d->txn_id, 'date' => Snapshot::ms($d->created_at)])->values(),
            'withdrawals' => DB::table('withdrawals')->where('user_id', $id)->orderByDesc('id')->limit(10)->get()->map(fn ($w) => ['ref' => $w->ref, 'amount' => self::n($w->amount), 'fee' => self::n($w->fee), 'net' => self::n($w->net), 'status' => $w->status, 'account' => $w->account, 'date' => Snapshot::ms($w->created_at)])->values(),
            'transfers' => $transfers->map(fn ($t) => ['kind' => $t->kind, 'dir' => $t->from_id == $id ? 'out' : 'in', 'other' => $t->kind === 'self' ? null : ($codes[$t->from_id == $id ? $t->to_id : $t->from_id] ?? null), 'amount' => self::n($t->amount), 'fee' => self::n($t->fee), 'date' => Snapshot::ms($t->created_at)])->values(),
            'ledger' => DB::table('ledger')->where('user_id', $id)->orderByDesc('id')->limit(25)->get()->map(fn ($l) => ['type' => $l->type, 'amount' => self::n($l->amount), 'note' => $l->note, 'date' => Snapshot::ms($l->created_at)])->values(),
            'audit' => $audit->map(fn ($a) => ['action' => $a->action, 'ip' => $a->ip, 'meta' => $a->meta ? json_decode($a->meta, true) : null, 'date' => Snapshot::ms($a->created_at)])->values(),
            'alerts' => DB::table('risk_alerts')->where('user_id', $id)->orderByRaw("CASE status WHEN 'open' THEN 0 ELSE 1 END")->orderByDesc('id')->limit(15)->get()
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'severity' => $a->severity, 'title' => $a->title, 'detail' => $a->detail, 'status' => $a->status, 'note' => $a->note, 'date' => Snapshot::ms($a->created_at)])->values(),
            'checks' => collect($this->risk->checkMember($id))->map(fn ($f) => ['code' => $f['code'], 'severity' => $f['severity'], 'title' => $f['title'], 'detail' => $f['detail']])->values(),
        ]);
    }
}
