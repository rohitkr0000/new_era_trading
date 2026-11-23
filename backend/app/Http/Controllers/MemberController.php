<?php

namespace App\Http\Controllers;

use App\Services\CompoundService;
use App\Services\Notifier;
use App\Services\OtpService;
use App\Services\PlanEngine;
use App\Services\Snapshot;
use App\Services\WalletService;
use App\Support\Audit;
use App\Support\Phone;
use App\Support\Settings;
use App\Support\PlanException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/** Member area API. Money actions require the member's transaction PIN. */
class MemberController extends Controller
{
    public function __construct(private PlanEngine $plan, private WalletService $wallet) {}

    /** Everything the dashboard needs, scoped to the signed-in user (admins get the admin snapshot). */
    public function bootstrap(Request $request, Snapshot $snapshot): JsonResponse
    {
        $user = $request->user();
        if ($user->blocked) return response()->json(['error' => 'Your account has been blocked.'], 403);
        if (config('app.accrue_on_request')) $this->plan->accrue();   // dev convenience; production uses the scheduler
        if ($user->isAdmin()) return response()->json($snapshot->admin($user));
        // the member dashboard is cached for a few seconds (dropped on the member's own writes and when their money changes)
        return response()->json(\App\Support\SafeCache::remember(Snapshot::key($user->id), (int) config('app.member_snapshot_cache', 15), fn () => $snapshot->member($user)));
    }

    private function member(Request $request)
    {
        $u = $request->user();
        if (! $u->isMember() || $u->blocked) abort(403, 'Members only.');
        return $u;
    }

    /** The 4–6 digit PIN: 5 wrong tries lock money actions for 15 minutes (a stolen login cannot guess it). */
    private function checkPin(Request $request): void
    {
        $u = $request->user();
        if (! $u->txn_pin) throw new PlanException('Set your transaction PIN first (Account → Change Txn PIN).');
        $key = 'pin:' . $u->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new PlanException('Too many wrong PINs. Money actions are locked for ' . max(1, (int) ceil(RateLimiter::availableIn($key) / 60)) . ' minute(s).');
        }
        if (! Hash::check((string) $request->input('pin'), $u->txn_pin)) {
            RateLimiter::hit($key, 15 * 60);
            Audit::log('security.wrong-pin', $u);
            throw new PlanException('Incorrect transaction PIN.');
        }
        RateLimiter::clear($key);
    }

    /** E-mail verification and KYC, required before money leaves the platform. */
    private function requireVerified($u): void
    {
        $s = Settings::all();
        if (! empty($s['emailVerifyRequired']) && ! $u->email_verified_at) throw new PlanException('Verify your e-mail first (Account → KYC & Verification), then request the withdrawal.');
        if (! empty($s['kycRequired']) && $u->kyc_status !== 'approved') {
            throw new PlanException($u->kyc_status === 'pending' ? 'Your KYC is under review. You can withdraw once it is approved.' : 'Complete your KYC first (Account → KYC & Verification).');
        }
    }

    public function purchase(Request $request): JsonResponse
    {
        $u = $this->member($request);
        $d = $request->validate(['amount' => 'required|numeric|min:0', 'source' => 'required|in:purchase,income', 'mode' => 'nullable|in:simple,compound', 'pin' => 'required']);
        $this->checkPin($request);
        $r = $this->plan->purchase($u, $d['amount'], $d['source'], $d['mode'] ?? 'simple');
        Audit::log('package.purchase', $u, 'order#' . $r['orderId'], ['amount' => $d['amount'], 'source' => $d['source'], 'mode' => $d['mode'] ?? 'simple']);
        return response()->json($r);
    }

    public function deposit(Request $request): JsonResponse
    {
        $u = $this->member($request);
        $d = $request->validate(['amount' => 'required|numeric|min:0', 'txnId' => 'required|string|max:120']);
        $id = $this->wallet->requestDeposit($u, $d['amount'], $d['txnId']);
        Audit::log('deposit.request', $u, 'deposit#' . $id, ['amount' => $d['amount'], 'txn' => $d['txnId']]);
        $row = DB::table('deposits')->where('id', $id)->first(['status', 'verified', 'verify_note']);
        return response()->json(['ok' => true, 'status' => $row->status, 'verified' => $row->verified, 'note' => $row->verify_note]);
    }

    public function transferSelf(Request $request): JsonResponse
    {
        $u = $this->member($request);
        $d = $request->validate(['amount' => 'required|numeric|min:0', 'pin' => 'required']);
        $this->checkPin($request);
        $this->wallet->transferToPurchase($u, $d['amount']);
        return response()->json(['ok' => true]);
    }

    /** Confirmation step of a P2P transfer: type a member ID, see the name. */
    public function lookup(Request $request, string $code): JsonResponse
    {
        $u = $this->member($request);
        $to = \App\Models\User::where('code', strtoupper(trim($code)))->where('role', 'member')->where('blocked', false)->first();
        if (! $to || $to->id === $u->id) return response()->json(['error' => $to ? 'You cannot transfer to yourself.' : 'Member ID not found.'], 404);
        return response()->json(['name' => $to->name, 'code' => $to->code]);
    }

    /** Step 1 of a P2P transfer: validate everything, e-mail a code that is valid for exactly this transfer. */
    public function transferOtp(Request $request, OtpService $otp): JsonResponse
    {
        $u = $this->member($request);
        $d = $request->validate(['toCode' => 'required|string|max:20', 'amount' => 'required|numeric|min:0', 'source' => 'nullable|in:purchase,income', 'pin' => 'required']);
        $this->checkPin($request);
        $p = $this->wallet->previewTransfer($u, $d['toCode'], $d['amount'], $d['source'] ?? 'purchase');
        $c = Settings::get('currency');
        $otp->send($u, 'transfer', 'P2P transfer of ' . $c . $p['amount'] . ' to ' . $p['to']->name . ' (' . $p['to']->code . ')', OtpService::context('p2p', $p['to']->code, $p['amount'], $p['source']));
        return response()->json(['ok' => true, 'sentTo' => preg_replace('/(?<=.).(?=[^@]*@)/', '*', $u->email),
            'to' => ['name' => $p['to']->name, 'code' => $p['to']->code], 'amount' => $p['amount'], 'fee' => $p['fee'], 'net' => $p['net'], 'source' => $p['source']]);
    }

    /** Step 2: the transfer happens only when the e-mailed code matches. */
    public function transferMember(Request $request, OtpService $otp): JsonResponse
    {
        $u = $this->member($request);
        $d = $request->validate(['toCode' => 'required|string|max:20', 'amount' => 'required|numeric|min:0', 'source' => 'nullable|in:purchase,income', 'pin' => 'required', 'otp' => 'required|string']);
        $this->checkPin($request);
        $p = $this->wallet->previewTransfer($u, $d['toCode'], $d['amount'], $d['source'] ?? 'purchase');
        $otp->verify($u, 'transfer', trim($d['otp']), OtpService::context('p2p', $p['to']->code, $p['amount'], $p['source']));
        $to = $this->wallet->transferToMember($u, $d['toCode'], $d['amount'], $p['source']);
        Audit::log('transfer.p2p', $u, 'user#' . $to->id, ['to' => $to->code, 'amount' => $p['amount'], 'fee' => $p['fee'], 'source' => $p['source']]);
        Notifier::transferDone($u, $to, $p['amount'], $p['fee'], $p['net']);
        return response()->json(['ok' => true, 'to' => ['name' => $to->name, 'code' => $to->code], 'fee' => $p['fee'], 'net' => $p['net']]);
    }

    /** Step 1 of a withdrawal: every rule is checked first, then a code for exactly this request is e-mailed. */
    public function withdrawOtp(Request $request, OtpService $otp): JsonResponse
    {
        $u = $this->member($request);
        $d = $request->validate(['amount' => 'required|numeric|min:0', 'method' => 'required|string|max:30', 'account' => 'required|string|max:160', 'pin' => 'required']);
        $this->checkPin($request);
        $this->requireVerified($u);
        $amount = $this->wallet->checkWithdrawal($u, $d['amount'], $d['account']);
        $c = Settings::get('currency');
        $otp->send($u, 'withdraw', 'withdrawal of ' . $c . $amount . ' to ' . $d['method'] . ' ' . substr(trim($d['account']), 0, 6) . '…' . substr(trim($d['account']), -4), OtpService::context('wd', $amount, $d['method'], trim($d['account'])));
        return response()->json(['ok' => true, 'sentTo' => preg_replace('/(?<=.).(?=[^@]*@)/', '*', $u->email)]);
    }

    /** Step 2: the withdrawal is created only when the e-mailed code matches. */
    public function withdraw(Request $request, OtpService $otp): JsonResponse
    {
        $u = $this->member($request);
        $d = $request->validate(['amount' => 'required|numeric|min:0', 'method' => 'required|string|max:30', 'account' => 'required|string|max:160', 'pin' => 'required', 'otp' => 'nullable|string']);
        $this->checkPin($request);
        $this->requireVerified($u);
        $amount = $this->wallet->checkWithdrawal($u, $d['amount'], $d['account']);
        if (empty($d['otp'])) throw new PlanException('Enter the verification code we e-mailed you.');
        $otp->verify($u, 'withdraw', trim($d['otp']), OtpService::context('wd', $amount, $d['method'], trim($d['account'])));
        $id = $this->wallet->requestWithdrawal($u, $amount, $d['method'], $d['account']);
        Audit::log('withdrawal.request', $u, 'withdrawal#' . $id, ['amount' => $amount, 'method' => $d['method'], 'account' => $d['account']]);
        Notifier::withdrawalRequested($id);   // withdrawal message by e-mail
        return response()->json(['ok' => true]);
    }

    /** Compounding balance: keep compounding, or withdraw the daily interest as cash. */
    public function compoundMode(Request $request, CompoundService $compound): JsonResponse
    {
        $u = $this->member($request);
        $d = $request->validate(['mode' => 'required|in:compound,cash', 'pin' => 'required']);
        $this->checkPin($request);
        $compound->setMode($u, $d['mode']);
        Audit::log('compound.mode', $u, null, ['mode' => $d['mode']]);
        return response()->json(['ok' => true, 'mode' => $d['mode']]);
    }

    public function profile(Request $request): JsonResponse
    {
        $u = $this->member($request);
        $d = $request->validate(['name' => 'required|string|max:120', 'phone' => ['required', 'string', 'regex:/^\d{10}$/'], 'country' => 'nullable|string|max:60',
            'city' => 'nullable|string|max:80', 'usdtAddress' => 'nullable|string|max:120'], ['phone.regex' => 'Enter a valid 10-digit mobile number (digits only).']);
        $key = Phone::key($d['phone'] ?? '');
        if ($key === null) throw new PlanException('Enter a valid 10-digit mobile number (digits only).');
        if (\App\Models\User::where('phone_key', $key)->where('id', '!=', $u->id)->exists()) throw new PlanException('This mobile number is already registered.');
        $u->phone_key = $key;
        $u->fill(['name' => trim($d['name']), 'phone' => $d['phone'], 'country' => $d['country'] ?? null, 'city' => $d['city'] ?? null, 'usdt_address' => $d['usdtAddress'] ?? null])->save();
        Audit::log('profile.update', $u, 'user#' . $u->id, ['payoutAddress' => $d['usdtAddress'] ?? null]);
        return response()->json(['ok' => true]);
    }

    /** Step 1 of a password change: e-mail a one-time code to the account's address. */
    public function passwordOtp(Request $request, OtpService $otp): JsonResponse
    {
        $u = $request->user();
        $otp->send($u, 'password', 'password change');
        return response()->json(['ok' => true, 'sentTo' => preg_replace('/(?<=.).(?=[^@]*@)/', '*', $u->email)]);
    }

    /** Step 2: current password + e-mailed OTP + new password. */
    public function password(Request $request, OtpService $otp): JsonResponse
    {
        $u = $request->user();
        $d = $request->validate(['old' => 'required|string', 'new' => ['required', 'string', 'max:100', \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers()], 'otp' => 'required|string']);
        if (! Hash::check($d['old'], $u->password)) throw new PlanException('Current password is incorrect.');
        $otp->verify($u, 'password', trim($d['otp']));
        $u->password = $d['new'];
        $u->save();
        $current = $request->user()->currentAccessToken();
        $u->tokens()->when($current, fn ($q) => $q->where('id', '!=', $current->id))->delete();   // sign out other devices
        Audit::log('auth.password-changed', $u);
        Notifier::passwordChanged($u);
        return response()->json(['ok' => true]);
    }

    public function pin(Request $request): JsonResponse
    {
        $u = $this->member($request);
        $d = $request->validate(['pin' => ['required', 'regex:/^\d{4,6}$/'], 'old' => 'nullable|string'], ['pin.regex' => 'PIN must be 4 to 6 digits.']);
        if ($u->txn_pin && ! Hash::check((string) ($d['old'] ?? ''), $u->txn_pin)) throw new PlanException('Current PIN is incorrect.');
        $u->txn_pin = Hash::make($d['pin']);
        $u->save();
        Audit::log('auth.pin-changed', $u);
        return response()->json(['ok' => true]);
    }

    /* ---------------- e-mail verification ---------------- */

    public function emailOtp(Request $request, OtpService $otp): JsonResponse
    {
        $u = $request->user();
        if ($u->email_verified_at) throw new PlanException('Your e-mail is already verified.');
        $otp->send($u, 'verify', 'e-mail verification');
        return response()->json(['ok' => true, 'sentTo' => preg_replace('/(?<=.).(?=[^@]*@)/', '*', $u->email)]);
    }

    public function emailVerify(Request $request, OtpService $otp): JsonResponse
    {
        $u = $request->user();
        $d = $request->validate(['otp' => 'required|string']);
        if (! $u->email_verified_at) {
            $otp->verify($u, 'verify', trim($d['otp']));
            $u->forceFill(['email_verified_at' => Carbon::now()])->save();
            Audit::log('auth.email-verified', $u);
        }
        return response()->json(['ok' => true]);
    }

    /* ---------------- KYC ---------------- */

    public function kycSubmit(Request $request): JsonResponse
    {
        $u = $this->member($request);
        if (in_array($u->kyc_status, ['pending', 'approved'], true)) throw new PlanException($u->kyc_status === 'approved' ? 'Your KYC is already approved.' : 'Your KYC is already under review.');
        $file = 'required|file|mimes:jpg,jpeg,png,pdf|max:4096';
        $d = $request->validate([
            'docType' => 'required|in:passport,national_id,driving_licence,aadhaar,pan',
            'docNumber' => ['required', 'string', 'min:4', 'max:40', 'regex:/^[A-Za-z0-9 \/-]+$/'],
            'front' => $file, 'back' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:4096', 'selfie' => 'required|file|mimes:jpg,jpeg,png|max:4096',
        ], ['front.max' => 'Each file must be 4 MB or smaller.', 'selfie.max' => 'Each file must be 4 MB or smaller.', 'back.max' => 'Each file must be 4 MB or smaller.',
            'docNumber.regex' => 'Document number may contain letters, digits, space, / and - only.']);

        $dir = 'kyc/' . $u->id;
        $store = fn ($f) => $f ? $f->storeAs($dir, bin2hex(random_bytes(16)) . '.' . $f->guessExtension(), 'local') : null;
        $id = DB::table('kyc_submissions')->insertGetId([
            'user_id' => $u->id, 'doc_type' => $d['docType'], 'doc_number' => trim($d['docNumber']), 'front_path' => $store($request->file('front')),
            'back_path' => $store($request->file('back')), 'selfie_path' => $store($request->file('selfie')), 'status' => 'pending', 'created_at' => Carbon::now(),
        ]);
        $u->forceFill(['kyc_status' => 'pending', 'kyc_note' => null])->save();
        Audit::log('kyc.submit', $u, 'kyc#' . $id, ['docType' => $d['docType']]);
        return response()->json(['ok' => true, 'status' => 'pending'], 201);
    }

    public function openTicket(Request $request): JsonResponse
    {
        $u = $this->member($request);
        $d = $request->validate(['subject' => 'required|string|max:160', 'message' => 'required|string|max:5000']);
        $id = DB::table('tickets')->insertGetId(['ref' => 'TMP-' . uniqid(), 'user_id' => $u->id, 'subject' => trim($d['subject']), 'message' => trim($d['message']), 'status' => 'open', 'created_at' => Carbon::now()]);
        DB::table('tickets')->where('id', $id)->update(['ref' => 'TKT-' . (1000 + $id)]);
        return response()->json(['ok' => true, 'ticket' => ['id' => $id, 'ref' => 'TKT-' . (1000 + $id)]], 201);
    }

    public function replyTicket(Request $request, int $id): JsonResponse
    {
        $u = $this->member($request);
        $d = $request->validate(['text' => 'required|string|max:5000']);
        $t = DB::table('tickets')->where('id', $id)->where('user_id', $u->id)->first();
        if (! $t) throw new PlanException('Ticket not found.');
        if ($t->status !== 'open') throw new PlanException('This ticket is closed.');
        DB::table('ticket_replies')->insert(['ticket_id' => $id, 'by' => 'user', 'text' => trim($d['text']), 'created_at' => Carbon::now()]);
        return response()->json(['ok' => true]);
    }
}
