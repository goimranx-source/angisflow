<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasskeyController;
use App\Http\Controllers\Auth\PasskeyLoginController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorSettingsController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Authentication
|------------------------------------------------------------------------------
|
| Three groups, and the boundaries between them are load-bearing:
|
|   guest    reachable only when signed out.
|   auth     signed in, but not necessarily past the second factor. The 2FA
|            challenge itself lives here — it has to, or somebody who has not
|            answered it could never be shown the question.
|   auth +   past the second factor. Everything that changes a credential sits
|   two-     here, because a session held at the challenge screen must not be
|   factor   able to disable the challenge.
|
| Throttles are per route and per minute. They are on every endpoint that can be
| submitted in bulk, including the ones that only send mail: an unthrottled
| "resend verification" is a way to use our mail reputation to flood somebody
| else's inbox.
|
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:20,1');

    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:10,1');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:6,1')->name('password.store');

    // Passkey sign-in. Two steps because WebAuthn is a challenge and a
    // response: the server issues something random, the device signs it.
    Route::post('passkey/login/options', [PasskeyLoginController::class, 'options'])
        ->middleware('throttle:20,1')->name('passkey.login.options');
    Route::post('passkey/login', [PasskeyLoginController::class, 'login'])
        ->middleware('throttle:20,1')->name('passkey.login');
});

Route::middleware('auth')->group(function () {
    // ── The second factor ────────────────────────────────────────────────────
    //
    // Deliberately outside the 'two-factor' middleware. Putting the challenge
    // behind the check that the challenge has been answered is a loop nobody
    // escapes, and it is a mistake that has shipped in real applications.
    Route::get('two-factor/challenge', [TwoFactorChallengeController::class, 'create'])
        ->name('two-factor.challenge');
    Route::post('two-factor/challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:10,1')->name('two-factor.verify');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // ── Email verification ───────────────────────────────────────────────────
    Route::get('verify-email', [EmailVerificationController::class, 'prompt'])->name('verification.notice');
    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('verify-email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')->name('verification.send');

    // ── Password confirmation ────────────────────────────────────────────────
    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);
});

/*
| Everything that changes how somebody proves who they are.
|
| Past the second factor, and behind a fresh password check ('password.confirm'
| ). A live session proves somebody signed in this morning; it does not prove
| the person at the keyboard now is the same one, and the difference matters
| most for exactly these routes.
*/
Route::middleware(['auth', 'two-factor', 'password.confirm'])->group(function () {
    Route::post('two-factor/enable', [TwoFactorSettingsController::class, 'begin'])
        ->middleware('throttle:10,1')->name('two-factor.enable');
    Route::post('two-factor/confirm', [TwoFactorSettingsController::class, 'confirm'])
        ->middleware('throttle:10,1')->name('two-factor.confirm');
    Route::delete('two-factor', [TwoFactorSettingsController::class, 'destroy'])
        ->name('two-factor.disable');
    Route::post('two-factor/recovery-codes', [TwoFactorSettingsController::class, 'regenerateRecoveryCodes'])
        ->middleware('throttle:5,1')->name('two-factor.recovery-codes');

    Route::post('passkeys/options', [PasskeyController::class, 'options'])
        ->middleware('throttle:10,1')->name('passkeys.options');
    Route::post('passkeys', [PasskeyController::class, 'register'])
        ->middleware('throttle:10,1')->name('passkeys.register');
    Route::patch('passkeys/{id}', [PasskeyController::class, 'update'])->name('passkeys.update');
    Route::delete('passkeys/{id}', [PasskeyController::class, 'destroy'])->name('passkeys.destroy');
});

// Listing passkeys needs no password confirmation — seeing which devices are
// registered is not a change, and a user checking that list is usually a user
// who is worried about something.
Route::middleware(['auth', 'two-factor'])->group(function () {
    Route::get('passkeys', [PasskeyController::class, 'index'])->name('passkeys.index');
});
