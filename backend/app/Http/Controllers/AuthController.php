<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Notifier;
use App\Services\OtpService;
use App\Services\PlanEngine;
use App\Services\Snapshot;
use App\Support\Audit;
use App\Support\PlanException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /** 8+ characters with letters and digits. */
    private function passwordRule(): array
    {
        return ['required', 'string', 'max:100', Password::min(8)->letters()->numbers()];
    }

    /** A login token: 24 hours for members, 2 hours for admins (then the person signs in again). */
    private function issueToken(User $user): string
    {
        return $user->createToken('web', ['*'], Carbon::now()->addMinutes($user->isAdmin() ? 120 : 1440))->plainTextToken;
    }

    private function regOtpKey(string $email): string { return 'reg-otp:' . strtolower(trim($email)); }
    private function regVerifiedKey(string $email): string { return 'reg-verified:' . strtolower(trim($email)); }

    /**
     * Registration's own e-mail check, step 1: e-mail a code to an address that is not registered yet (no account
     * exists to attach it to, so this uses a short-lived cache entry instead of the `otps` table).
     */
    public function registerEmailOtp(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => 'nullable|string|max:120', 'email' => 'required|email|max:160']);
        $email = strtolower(trim($d['email']));
        if (User::where('email', $email)->exists()) throw new PlanException('This e-mail is already registered.');
        $key = $this->regOtpKey($email);
        $prev = Cache::get($key);
        if ($prev && (Carbon::now()->timestamp - $prev['created']) < 60) throw new PlanException('Please wait a minute before requesting another code.');
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put($key, ['hash' => Hash::make($code), 'attempts' => 0, 'created' => Carbon::now()->timestamp], now()->addMinutes(10));
        Notifier::otpToEmail($email, trim((string) ($d['name'] ?? '')) ?: 'there', $code, 10);
        return response()->json(['ok' => true, 'sentTo' => preg_replace('/(?<=.).(?=[^@]*@)/', '*', $email)]);
    }

    /** Step 2: a correct code marks the e-mail "proven" for the next 30 minutes, so /register can skip asking again. */
    public function registerEmailVerify(Request $request): JsonResponse
    {
        $d = $request->validate(['email' => 'required|email|max:160', 'otp' => 'required|string']);
        $email = strtolower(trim($d['email']));
        $key = $this->regOtpKey($email);
        $row = Cache::get($key);
        if (! $row) throw new PlanException('Request a verification code first.');
        if (($row['attempts'] ?? 0) >= 5) { Cache::forget($key); throw new PlanException('Too many wrong attempts. Request a new code.'); }
        if (! preg_match('/^\d{6}$/', trim($d['otp'])) || ! Hash::check(trim($d['otp']), $row['hash'])) {
            $row['attempts'] = ($row['attempts'] ?? 0) + 1;
            Cache::put($key, $row, now()->addMinutes(10));
            throw new PlanException('Incorrect verification code.');
        }
        Cache::forget($key);
        Cache::put($this->regVerifiedKey($email), true, now()->addMinutes(30));
        return response()->json(['ok' => true]);
    }

    public function register(Request $request, PlanEngine $plan, OtpService $otp): JsonResponse
    {
        $d = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:160|unique:users,email',
            'password' => $this->passwordRule(),
            'phone' => ['required', 'string', 'regex:/^\d{10}$/'],
            'pin' => ['required', 'regex:/^\d{4,6}$/'],
            'sponsorCode' => 'required|string|max:20',
            'leg' => 'nullable|in:left,right',
            'parentId' => 'nullable|integer',
            'amount' => 'nullable|numeric|min:0',
        ], [
            'email.unique' => 'This email is already registered.', 'phone.required' => 'Your mobile number is required.',
            'phone.regex' => 'Enter a valid 10-digit mobile number (digits only).',
            'pin.required' => 'Set a transaction PIN to continue.', 'pin.regex' => 'PIN must be 4 to 6 digits.',
        ]);

        // A joining amount at sign-up is a simulated payment. Outside demo mode money only enters through a verified deposit.
        if (! config('app.demo_mode')) unset($d['amount']);

        $user = $plan->register($d);
        Audit::log('auth.register', $user, 'user#' . $user->id, ['sponsor' => $d['sponsorCode']]);
        Notifier::welcome($user);   // registration message (e-mail from New Era Trading)
        if (Cache::pull($this->regVerifiedKey($user->email))) {
            // the member already proved this e-mail during sign-up (step 1's "Verify" button) — no need to ask again
            $user->forceFill(['email_verified_at' => Carbon::now()])->save();
            Audit::log('auth.email-verified', $user, null, ['via' => 'registration']);
        } else {
            try { $otp->send($user, 'verify', 'e-mail verification'); } catch (PlanException $e) { /* just created: cannot be throttled */ }
        }
        return response()->json(['token' => $this->issueToken($user), 'user' => Snapshot::user($user, true)], 201);
    }

    /** Step 1 (and, for members, the only step). Admins must also confirm an e-mailed code when ADMIN_2FA is on. */
    public function login(Request $request, OtpService $otp): JsonResponse
    {
        $user = $this->checkCredentials($request);
        if ($user instanceof JsonResponse) return $user;

        if ($user->isAdmin() && config('app.admin_2fa')) {
            try { $otp->send($user, 'login', 'admin sign-in'); } catch (PlanException $e) { /* a code was sent less than a minute ago: it is still valid */ }
            return response()->json(['otp_required' => true, 'sentTo' => preg_replace('/(?<=.).(?=[^@]*@)/', '*', $user->email)], 202);
        }
        return $this->finishLogin($request, $user);
    }

    /** Step 2 for admins: password again + the e-mailed code. */
    public function loginVerify(Request $request, OtpService $otp): JsonResponse
    {
        $request->validate(['otp' => 'required|string']);
        $user = $this->checkCredentials($request);
        if ($user instanceof JsonResponse) return $user;
        if (! $user->isAdmin() || ! config('app.admin_2fa')) return response()->json(['error' => 'No code is needed for this account.'], 422);
        try {
            $otp->verify($user, 'login', trim((string) $request->input('otp')));
        } catch (PlanException $e) {
            RateLimiter::hit('login:' . sha1(strtolower(trim($request->input('email'))) . '|' . $request->ip()), 15 * 60);
            Audit::log('auth.login-failed', $user, null, ['reason' => '2fa code']);
            throw $e;
        }
        return $this->finishLogin($request, $user);
    }

    /** Password check with the per-account lockout. The login field accepts either a real e-mail address
     *  or the member's generated ID (code). Returns the user, or the JSON error response. */
    private function checkCredentials(Request $request): User|JsonResponse
    {
        $d = $request->validate(['email' => 'required|string|max:160', 'password' => 'required|string']);
        $identifier = strtolower(trim($d['email']));
        $key = 'login:' . sha1($identifier . '|' . $request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $mins = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));
            return response()->json(['error' => "Too many failed attempts. Try again in {$mins} minute(s) or reset your password."], 429);
        }
        $user = filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? User::where('email', $identifier)->first()
            : User::where('code', strtoupper($identifier))->first();
        if (! $user || ! Hash::check($d['password'], $user->password)) {
            RateLimiter::hit($key, 15 * 60);
            Audit::log('auth.login-failed', $user, null, ['identifier' => $identifier]);
            return response()->json(['error' => 'Invalid email/member ID or password.'], 422);
        }
        if ($user->blocked) return response()->json(['error' => 'Your account has been blocked. Contact support.'], 403);
        return $user;
    }

    private function finishLogin(Request $request, User $user): JsonResponse
    {
        RateLimiter::clear('login:' . sha1(strtolower(trim((string) $request->input('email'))) . '|' . $request->ip()));
        $user->forceFill(['last_login_at' => Carbon::now(), 'last_login_ip' => $request->ip()])->save();
        Audit::log('auth.login', $user);
        return response()->json(['token' => $this->issueToken($user), 'user' => Snapshot::user($user, true)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['ok' => true]);
    }

    /* ---------------- forgot / reset password (e-mail OTP) ---------------- */

    /** Always answers "ok" so the form cannot be used to find out which e-mails have an account. */
    public function forgotPassword(Request $request, OtpService $otp): JsonResponse
    {
        $d = $request->validate(['email' => 'required|email']);
        $user = User::where('email', strtolower(trim($d['email'])))->first();
        if ($user && ! $user->blocked) {
            try { $otp->send($user, 'reset', 'password reset'); Audit::log('auth.reset-requested', $user); } catch (PlanException $e) { /* resend too soon: stay silent */ }
        }
        return response()->json(['ok' => true]);
    }

    public function resetPassword(Request $request, OtpService $otp): JsonResponse
    {
        $d = $request->validate(['email' => 'required|email', 'otp' => 'required|string', 'password' => $this->passwordRule()]);
        $user = User::where('email', strtolower(trim($d['email'])))->first();
        if (! $user || $user->blocked) throw new PlanException('Incorrect verification code.');
        $otp->verify($user, 'reset', trim($d['otp']));
        $user->password = $d['password'];
        $user->save();
        $user->tokens()->delete();   // sign out everywhere
        Audit::log('auth.password-reset', $user);
        Notifier::passwordReset($user);
        return response()->json(['ok' => true]);
    }
}
