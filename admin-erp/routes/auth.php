<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\MfaChallengeController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

/*
 * SYSTEM-010: this is an internal, invite-only ERP with exactly one
 * intended account-creation path — the seeded Super Admin
 * (database/seeders/AdminUserSeeder.php), which deliberately never resets
 * a real admin's password. Breeze's public self-registration routes are
 * gone entirely (not disabled-in-place) — RegisteredUserController and
 * auth/register.blade.php were dead weight the moment they were removed,
 * so both routes now correctly 404 rather than carrying a controller that
 * exists only to be unreachable. See RegistrationDisabledTest for the
 * regression test.
 */
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');

    /*
     * The second step of signing in (docs/MFA_SECURITY.md). Guest routes: the password was right but nobody is signed in
     * until a code completes the challenge. Throttled per account, per session and per address in the controller
     * (App\Support\Mfa\MfaThrottle), never by address alone.
     */
    Route::get('two-factor-challenge', [MfaChallengeController::class, 'show'])->name('mfa.challenge');
    Route::post('two-factor-challenge', [MfaChallengeController::class, 'verify'])->name('mfa.challenge.verify');
    Route::post('two-factor-challenge/recovery', [MfaChallengeController::class, 'recovery'])->name('mfa.challenge.recovery');
    Route::post('two-factor-challenge/cancel', [MfaChallengeController::class, 'cancel'])->name('mfa.challenge.cancel');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])->name('password.confirm.store');

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
