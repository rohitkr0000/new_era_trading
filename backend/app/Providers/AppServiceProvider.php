<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->rateLimits();
    }

    /**
     * Rate limits. Every limiter has its OWN counter: with Laravel's plain `throttle:60,1` all routes of one signed-in user
     * (or one IP) share a single counter, so a page load could use up the budget of the transfer or login route.
     * Signed-in limits are per USER, public ones per IP (generous: a mobile network or an office can share one address).
     */
    private function rateLimits(): void
    {
        $user = fn (Request $r) => $r->user()?->getAuthIdentifier() ?: $r->ip();
        $ip = fn (Request $r) => $r->ip();
        $limits = [   // name => [per minute, key resolver]
            'public'       => [1200, $ip],   // home page numbers
            'sponsor'      => [300, $ip],    // sponsor-code check on the sign-up page
            'register'     => [20, $ip],
            'login'        => [20, $ip],
            'forgot'       => [5, $ip],
            'reset'        => [10, $ip],
            'user'         => [240, $user],  // everything a signed-in member does
            'otp-request'  => [10, $user],   // asking for an e-mailed code (transfer / withdrawal / e-mail / password)
            'otp-confirm'  => [20, $user],   // the step that spends the code (transfer, withdrawal, verify, password change)
            'lookup'       => [40, $user],   // member-ID name check for P2P
            'kyc'          => [6, $user],
            'admin-file'   => [120, $user],
            'admin-action' => [20, $user],
        ];
        foreach ($limits as $name => [$perMinute, $key]) {
            RateLimiter::for($name, fn (Request $r) => Limit::perMinute($perMinute)->by($name . '|' . $key($r)));
        }
    }
}
