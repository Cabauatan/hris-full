<?php

use App\Http\Controllers\Api\V1\ApplicantAuthenticationController;
use App\Http\Controllers\Api\V1\TelegramConnectionController;
use App\Http\Middleware\EnsureApplicantIsActive;
use App\Http\Middleware\HandleApiBusinessExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/applicant/auth')->name('api.v1.applicant.auth.')->middleware('web')->group(function (): void {
    Route::get('csrf-cookie', [ApplicantAuthenticationController::class, 'csrfCookie'])->name('csrf-cookie');
    Route::post('register', [ApplicantAuthenticationController::class, 'register'])->middleware('throttle:applicant-registration')->name('register');
    Route::post('login', [ApplicantAuthenticationController::class, 'login'])->middleware('throttle:applicant-login')->name('login');
    Route::post('logout', [ApplicantAuthenticationController::class, 'logout'])->middleware(['auth:applicant', 'auth.session'])->name('logout');
    Route::get('me', [ApplicantAuthenticationController::class, 'me'])->middleware(['auth:applicant', 'auth.session', EnsureApplicantIsActive::class])->name('me');
    Route::middleware(['auth:applicant', 'auth.session', EnsureApplicantIsActive::class])->prefix('telegram')->name('telegram.')->group(function (): void {
        Route::get('/', [TelegramConnectionController::class, 'show'])->name('show');
        Route::post('link', [TelegramConnectionController::class, 'link'])->middleware('throttle:telegram-links')->name('link');
        Route::delete('/', [TelegramConnectionController::class, 'destroy'])->name('destroy');
    });
});

Route::prefix('v1/applicant')->name('api.v1.applicant.')->middleware(['web', 'auth:applicant', 'auth.session', EnsureApplicantIsActive::class, HandleApiBusinessExceptions::class])->group(function (): void {
    Route::post('broadcasting/auth', function (Request $request): JsonResponse {
        $request->validate([
            'socket_id' => ['required', 'string', 'max:100', 'regex:/\A\d+\.\d+\z/'],
            'channel_name' => ['required', 'string', 'max:255', 'regex:/\Aprivate-App\.Models\.Applicant\.[1-9]\d*\z/'],
        ]);

        return response()->json(Broadcast::auth($request))->header('Cache-Control', 'no-store');
    })->middleware('throttle:applicant-broadcast-auth')->name('broadcasting.auth');
});
