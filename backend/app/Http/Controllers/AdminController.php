<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Notifier;
use App\Services\Snapshot;
use App\Services\WalletService;
use App\Support\Audit;
use App\Support\PlanException;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Admin panel API (role = admin). */
class AdminController extends Controller
{
    public function __construct(private WalletService $wallet) {}

    private function admin(Request $request): User
    {
        $u = $request->user();
        if (! $u->isAdmin()) abort(403, 'Admins only.');
        return $u;
    }

    public function block(Request $request, int $id): JsonResponse
    {
        $this->admin($request);
        $u = User::where('role', 'member')->findOrFail($id);
        $u->blocked = (bool) $request->boolean('blocked');
        $u->save();
        if ($u->blocked) $u->tokens()->delete();
        Audit::log($u->blocked ? 'member.block' : 'member.unblock', $request->user(), 'user#' . $u->id, ['code' => $u->code]);
        return response()->json(['ok' => true]);
    }

    /**
     * Admin support tool: sign in as a member without ever knowing their password. Issues a short-lived
     * (1 hour) token for that member's own account; fully audit-logged. Never works on another admin.
     */
    public function impersonate(Request $request, int $id): JsonResponse
    {
        $admin = $this->admin($request);
        $u = User::where('role', 'member')->findOrFail($id);
        if ($u->blocked) throw new PlanException('This member is blocked — unblock them first.');
        $token = $u->createToken('impersonate-by-' . $admin->code, ['*'], Carbon::now()->addHour())->plainTextToken;
        Audit::log('admin.impersonate', $admin, 'user#' . $u->id, ['code' => $u->code]);
        return response()->json(['ok' => true, 'token' => $token, 'user' => Snapshot::user($u, true)]);
    }

    public function deposit(Request $request, int $id): JsonResponse
    {
        $this->admin($request);
        $d = $request->validate(['status' => 'required|in:approved,rejected']);
        $this->wallet->processDeposit($id, $d['status']);
        Audit::log('deposit.' . $d['status'], $request->user(), 'deposit#' . $id);
        return response()->json(['ok' => true]);
    }

    /** Re-check a pending deposit on the blockchain. */
    public function recheckDeposit(Request $request, int $id): JsonResponse
    {
        $this->admin($request);
        $r = $this->wallet->verifyDeposit($id);
        if ($r === null) throw new PlanException('On-chain check is not available (no API key, or the deposit is not pending).');
        return response()->json(['ok' => true] + $r);
    }

    public function withdrawal(Request $request, int $id): JsonResponse
    {
        $this->admin($request);
        $d = $request->validate([
            'status' => 'required|in:approved,rejected',
            'payoutTxid' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9]+$/'],
            'note' => 'nullable|string|max:255',
        ], ['payoutTxid.regex' => 'The payout transaction hash may contain letters and digits only.']);
        if ($d['status'] === 'approved' && empty($d['payoutTxid'])) throw new PlanException('Enter the payout transaction hash (the on-chain transfer you sent) to approve.');
        $this->wallet->processWithdrawal($id, $d['status'], $d['payoutTxid'] ?? null, $d['note'] ?? null);
        Audit::log('withdrawal.' . $d['status'], $request->user(), 'withdrawal#' . $id, ['txid' => $d['payoutTxid'] ?? null, 'note' => $d['note'] ?? null]);
        Notifier::withdrawalProcessed($id);   // approved / rejected message by e-mail
        return response()->json(['ok' => true]);
    }

    public function replyTicket(Request $request, int $id): JsonResponse
    {
        $this->admin($request);
        $d = $request->validate(['text' => 'required|string|max:5000']);
        if (! DB::table('tickets')->where('id', $id)->exists()) throw new PlanException('Ticket not found.');
        DB::table('ticket_replies')->insert(['ticket_id' => $id, 'by' => 'admin', 'text' => trim($d['text']), 'created_at' => Carbon::now()]);
        Audit::log('ticket.reply', $request->user(), 'ticket#' . $id);
        return response()->json(['ok' => true]);
    }

    public function closeTicket(Request $request, int $id): JsonResponse
    {
        $this->admin($request);
        DB::table('tickets')->where('id', $id)->update(['status' => 'closed']);
        return response()->json(['ok' => true]);
    }

    public function settings(Request $request): JsonResponse
    {
        $this->admin($request);
        $patch = $request->validate([
            'roiPct' => 'numeric|min:0|max:100', 'roiDays' => 'integer|min:0', 'directPct' => 'numeric|min:0|max:100', 'retopupPct' => 'numeric|min:0|max:100',
            'levelPct' => 'array|max:30', 'levelPct.*' => 'numeric|min:0|max:100', 'capNonWorking' => 'numeric|min:0', 'capWorking' => 'numeric|min:0', 'capDirectsRequired' => 'integer|min:0|max:100', 'levelUnlockPerLevel' => 'boolean', 'joinMultiple' => 'numeric|min:1', 'capMode' => 'in:business,directs', 'capBusinessMultiple' => 'numeric|min:0|max:100',
            'minJoin' => 'numeric|min:1', 'minWithdraw' => 'numeric|min:0', 'withdrawMultiple' => 'numeric|min:0', 'withdrawFeePct' => 'numeric|min:0|max:100', 'p2pFeePct' => 'numeric|min:0|max:100', 'minTransfer' => 'numeric|min:0',
            'withdrawStart' => 'integer|min:0|max:23', 'withdrawEnd' => 'integer|min:1|max:24', 'receiptHours' => 'integer|min:1|max:720',
            'tradingDays' => 'array', 'tradingDays.*' => 'integer|min:0|max:6', 'timezone' => 'timezone', 'minDeposit' => 'numeric|min:0',
            'depositNetwork' => 'string|max:60', 'depositAddress' => ['string', 'max:120', 'regex:/^0x[0-9a-fA-F]{40}$/'],
            'emailVerifyRequired' => 'boolean', 'kycRequired' => 'boolean', 'autoApproveDeposits' => 'boolean',
        ]);
        foreach (['levelPct', 'tradingDays'] as $k) if (isset($patch[$k])) $patch[$k] = array_values(array_map('floatval', $patch[$k]));
        if (isset($patch['tradingDays'])) $patch['tradingDays'] = array_map('intval', $patch['tradingDays']);
        $before = Settings::all();
        $after = Settings::update($patch);
        $changed = [];
        foreach ($patch as $k => $v) if (($before[$k] ?? null) != ($after[$k] ?? null)) $changed[$k] = ['from' => $before[$k] ?? null, 'to' => $after[$k] ?? null];
        if ($changed) Audit::log('settings.update', $request->user(), null, $changed);
        return response()->json(['ok' => true, 'settings' => $after]);
    }

    /** Upload your own deposit QR image (e.g. exported from your wallet app). It replaces the QR the site draws from the address. */
    public function depositQrUpload(Request $request): JsonResponse
    {
        $this->admin($request);
        $request->validate(['qr' => 'required|file|mimes:png,jpg,jpeg,webp|max:1024']);
        $f = $request->file('qr');
        $info = @getimagesize($f->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) return response()->json(['error' => 'That file is not a valid PNG, JPG or WEBP image.'], 422);
        if ($info[0] > 3000 || $info[1] > 3000 || $info[0] < 100 || $info[1] < 100) return response()->json(['error' => 'Image must be between 100 and 3000 pixels wide and tall.'], 422);
        $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2]];
        $dir = storage_path('app/private/branding');
        if (! is_dir($dir)) mkdir($dir, 0775, true);
        foreach (glob($dir . '/deposit-qr.*') ?: [] as $old) @unlink($old);
        $f->move($dir, 'deposit-qr.' . $ext);   // fixed name + extension from the real image type: the original file name is never used
        Settings::update(['depositQr' => $ext . ':' . time()]);
        Audit::log('settings.deposit-qr', $request->user(), null, ['action' => 'uploaded']);
        return response()->json(['ok' => true, 'settings' => Settings::all()]);
    }

    public function depositQrRemove(Request $request): JsonResponse
    {
        $this->admin($request);
        foreach (glob(storage_path('app/private/branding') . '/deposit-qr.*') ?: [] as $old) @unlink($old);
        Settings::update(['depositQr' => null]);
        Audit::log('settings.deposit-qr', $request->user(), null, ['action' => 'removed']);
        return response()->json(['ok' => true, 'settings' => Settings::all()]);
    }

    public function announce(Request $request): JsonResponse
    {
        $this->admin($request);
        $d = $request->validate(['text' => 'required|string|max:1000']);
        DB::table('announcements')->insert(['text' => trim($d['text']), 'created_at' => Carbon::now()]);
        Audit::log('announcement.post', $request->user(), null, ['text' => mb_substr(trim($d['text']), 0, 120)]);
        return response()->json(['ok' => true]);
    }

    /* ---------------- KYC review ---------------- */

    public function kycList(Request $request): JsonResponse
    {
        $this->admin($request);
        $rows = DB::table('kyc_submissions as k')->join('users as u', 'u.id', '=', 'k.user_id')
            ->orderByRaw("CASE k.status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END")->orderByDesc('k.id')->limit(300)
            ->get(['k.id', 'k.user_id', 'u.name', 'u.code', 'u.email', 'k.doc_type', 'k.doc_number', 'k.status', 'k.note', 'k.created_at', 'k.reviewed_at', 'k.back_path']);
        return response()->json(['items' => $rows->map(fn ($r) => [
            'id' => $r->id, 'userId' => $r->user_id, 'name' => $r->name, 'code' => $r->code, 'email' => $r->email, 'docType' => $r->doc_type, 'docNumber' => $r->doc_number,
            'status' => $r->status, 'note' => $r->note, 'hasBack' => (bool) $r->back_path, 'date' => Snapshot::ms($r->created_at), 'reviewedAt' => Snapshot::ms($r->reviewed_at),
        ])->values()]);
    }

    /** Streams one uploaded document to an admin (documents are never publicly reachable). */
    public function kycFile(Request $request, int $id, string $which)
    {
        $this->admin($request);
        $k = DB::table('kyc_submissions')->where('id', $id)->first();
        $col = ['front' => 'front_path', 'back' => 'back_path', 'selfie' => 'selfie_path'][$which] ?? null;
        if (! $k || ! $col || ! $k->$col || ! Storage::disk('local')->exists($k->$col)) abort(404);
        Audit::log('kyc.view-file', $request->user(), 'kyc#' . $id, ['file' => $which]);
        return Storage::disk('local')->response($k->$col, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function kycReview(Request $request, int $id): JsonResponse
    {
        $admin = $this->admin($request);
        $d = $request->validate(['status' => 'required|in:approved,rejected', 'note' => 'nullable|string|max:255']);
        if ($d['status'] === 'rejected' && empty($d['note'])) throw new PlanException('Write the reason for rejecting, so the member can fix it.');
        $k = DB::table('kyc_submissions')->where('id', $id)->first();
        if (! $k || $k->status !== 'pending') throw new PlanException('This submission was already reviewed.');
        DB::transaction(function () use ($id, $k, $d, $admin) {
            DB::table('kyc_submissions')->where('id', $id)->update(['status' => $d['status'], 'note' => $d['note'] ?? null, 'reviewed_by' => $admin->id, 'reviewed_at' => Carbon::now()]);
            User::where('id', $k->user_id)->update(['kyc_status' => $d['status'], 'kyc_note' => $d['note'] ?? null]);
        });
        Audit::log('kyc.' . $d['status'], $admin, 'kyc#' . $id, ['user' => $k->user_id, 'note' => $d['note'] ?? null]);
        if ($u = User::find($k->user_id)) Notifier::kycResult($u, $d['status'] === 'approved', $d['note'] ?? null);
        return response()->json(['ok' => true]);
    }

    /* ---------------- audit log ---------------- */

    public function audit(Request $request): JsonResponse
    {
        $this->admin($request);
        $q = DB::table('audit_logs')->orderByDesc('id');
        if ($a = $request->query('action')) $q->where('action', 'like', preg_replace('/[^a-z0-9._-]/i', '', $a) . '%');
        if ($s = $request->query('q')) {
            $s = '%' . addcslashes(mb_substr((string) $s, 0, 60), '%_\\') . '%';
            $q->where(fn ($w) => $w->where('actor', 'like', $s)->orWhere('target', 'like', $s)->orWhere('ip', 'like', $s));
        }
        $rows = $q->limit(min(500, max(1, (int) $request->query('limit', 200))))->get();
        return response()->json(['items' => $rows->map(fn ($r) => [
            'id' => $r->id, 'actor' => $r->actor, 'action' => $r->action, 'target' => $r->target, 'meta' => $r->meta ? json_decode($r->meta, true) : null,
            'ip' => $r->ip, 'date' => Snapshot::ms($r->created_at),
        ])->values()]);
    }

    /** Local development only: wipe and reseed the demo network. */
    public function reset(Request $request): JsonResponse
    {
        $this->admin($request);
        if (! app()->environment('local') || ! config('app.demo_mode')) abort(403, 'Reset is disabled outside local demo mode.');
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Settings::flush();
        return response()->json(['ok' => true]);
    }
}
