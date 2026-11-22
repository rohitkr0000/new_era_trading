<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\RiskController;
use Illuminate\Support\Facades\Route;

/*
 | New Era Trading API  —  base URL: http://127.0.0.1:8000/api
 | Auth: Sanctum bearer token (Authorization: Bearer <token>) returned by /login and /register.
 */

// public
Route::get('/public', [PublicController::class, 'summary'])->middleware('throttle:public');
Route::get('/public/deposit-qr', [PublicController::class, 'depositQr'])->middleware('throttle:public');
Route::get('/public/sponsor/{code}', [PublicController::class, 'sponsor'])->middleware('throttle:sponsor');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/login/verify', [AuthController::class, 'loginVerify'])->middleware('throttle:login');
Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:forgot');
Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:reset');

// every signed-in call is limited per USER (not per IP, so offices and mobile networks sharing one address are not hurt)
Route::middleware(['auth:sanctum', 'throttle:user', 'forget.snapshot'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/bootstrap', [MemberController::class, 'bootstrap']);
    Route::post('/account/password/otp', [MemberController::class, 'passwordOtp'])->middleware('throttle:otp-request');
    Route::put('/account/password', [MemberController::class, 'password'])->middleware('throttle:otp-confirm');
    Route::post('/account/email/otp', [MemberController::class, 'emailOtp'])->middleware('throttle:otp-request');
    Route::post('/account/email/verify', [MemberController::class, 'emailVerify'])->middleware('throttle:otp-confirm');

    Route::prefix('member')->group(function () {
        Route::post('/purchase', [MemberController::class, 'purchase']);
        Route::post('/deposits', [MemberController::class, 'deposit']);
        Route::post('/transfer-self', [MemberController::class, 'transferSelf']);
        Route::get('/lookup/{code}', [MemberController::class, 'lookup'])->middleware('throttle:lookup');
        Route::post('/transfer/otp', [MemberController::class, 'transferOtp'])->middleware('throttle:otp-request');
        Route::post('/transfer', [MemberController::class, 'transferMember'])->middleware('throttle:otp-confirm');
        Route::post('/compound/mode', [MemberController::class, 'compoundMode'])->middleware('throttle:otp-confirm');
        Route::post('/withdrawals/otp', [MemberController::class, 'withdrawOtp'])->middleware('throttle:otp-request');
        Route::post('/withdrawals', [MemberController::class, 'withdraw'])->middleware('throttle:otp-confirm');
        Route::put('/profile', [MemberController::class, 'profile']);
        Route::post('/kyc', [MemberController::class, 'kycSubmit'])->middleware('throttle:kyc');
        Route::put('/pin', [MemberController::class, 'pin']);
        Route::post('/tickets', [MemberController::class, 'openTicket']);
        Route::post('/tickets/{id}/reply', [MemberController::class, 'replyTicket']);
    });

    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::post('/members/{id}/block', [AdminController::class, 'block']);
        Route::get('/members/{id}', [RiskController::class, 'member'])->whereNumber('id');
        Route::post('/members/{id}/revoke', [RiskController::class, 'revoke'])->whereNumber('id')->middleware('throttle:admin-action');
        Route::put('/members/{id}/contact', [RiskController::class, 'updateContact'])->whereNumber('id')->middleware('throttle:admin-action');
        Route::post('/members/{id}/password', [RiskController::class, 'resetPassword'])->whereNumber('id')->middleware('throttle:admin-action');
        Route::get('/alerts', [RiskController::class, 'alerts']);
        Route::post('/alerts/scan', [RiskController::class, 'scan'])->middleware('throttle:admin-action');
        Route::post('/alerts/{id}', [RiskController::class, 'alertUpdate'])->whereNumber('id');
        Route::post('/deposits/{id}', [AdminController::class, 'deposit']);
        Route::post('/deposits/{id}/recheck', [AdminController::class, 'recheckDeposit'])->middleware('throttle:admin-action');
        Route::get('/kyc', [AdminController::class, 'kycList']);
        Route::get('/kyc/{id}/file/{which}', [AdminController::class, 'kycFile'])->middleware('throttle:admin-file');
        Route::post('/kyc/{id}', [AdminController::class, 'kycReview']);
        Route::get('/audit', [AdminController::class, 'audit']);
        Route::post('/withdrawals/{id}', [AdminController::class, 'withdrawal']);
        Route::post('/tickets/{id}/reply', [AdminController::class, 'replyTicket']);
        Route::post('/tickets/{id}/close', [AdminController::class, 'closeTicket']);
        Route::put('/settings', [AdminController::class, 'settings']);
        Route::post('/announcements', [AdminController::class, 'announce']);
        Route::post('/deposit-qr', [AdminController::class, 'depositQrUpload'])->middleware('throttle:admin-action');
        Route::delete('/deposit-qr', [AdminController::class, 'depositQrRemove'])->middleware('throttle:admin-action');
        Route::post('/reset', [AdminController::class, 'reset']);
    });
});
