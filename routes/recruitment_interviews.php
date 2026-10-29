<?php

use App\Http\Controllers\Api\V1\RecruitmentInterviewController;
use App\Http\Controllers\Api\V1\RecruitmentInterviewMessageController;
use App\Http\Middleware\EnsureApplicantIsActive;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleApiBusinessExceptions;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/recruitment')->name('api.v1.recruitment.')->middleware([
    'web', 'auth:web', 'auth.session', EnsureUserIsActive::class, HandleApiBusinessExceptions::class,
])->group(function (): void {
    Route::get('interviewers', [RecruitmentInterviewController::class, 'interviewers'])->name('interviewers.index');
    Route::get('applications/{jobApplication}/interviews', [RecruitmentInterviewController::class, 'index'])->name('applications.interviews.index');
    Route::post('applications/{jobApplication}/interviews', [RecruitmentInterviewController::class, 'store'])->name('applications.interviews.store');
    Route::get('interviews/{recruitmentInterview}', [RecruitmentInterviewController::class, 'show'])->name('interviews.show');
    Route::post('interviews/{recruitmentInterview}/cancel', [RecruitmentInterviewController::class, 'cancel'])->name('interviews.cancel');
    Route::post('interviews/{recruitmentInterview}/complete', [RecruitmentInterviewController::class, 'complete'])->name('interviews.complete');
    Route::post('interviews/{recruitmentInterview}/join', [RecruitmentInterviewController::class, 'join'])->middleware('throttle:interview-join')->name('interviews.join');
    Route::post('interviews/{recruitmentInterview}/signal', [RecruitmentInterviewController::class, 'signal'])->middleware('throttle:interview-signal')->name('interviews.signal');
    Route::get('interviews/{recruitmentInterview}/messages', [RecruitmentInterviewMessageController::class, 'index'])->middleware('throttle:interview-messages-read')->name('interviews.messages.index');
    Route::post('interviews/{recruitmentInterview}/messages', [RecruitmentInterviewMessageController::class, 'store'])->middleware('throttle:interview-messages-send')->name('interviews.messages.store');
    Route::post('interviews/broadcasting/auth', [RecruitmentInterviewController::class, 'authorizeBroadcast'])->middleware('throttle:interview-broadcast-auth')->name('interviews.broadcasting.auth');
});

Route::prefix('v1/applicant')->name('api.v1.applicant.')->middleware([
    'web', 'auth:applicant', 'auth.session', EnsureApplicantIsActive::class, HandleApiBusinessExceptions::class,
])->group(function (): void {
    Route::get('applications/{jobApplication}/interviews', [RecruitmentInterviewController::class, 'index'])->name('applications.interviews.index');
    Route::get('interviews/{recruitmentInterview}', [RecruitmentInterviewController::class, 'show'])->name('interviews.show');
    Route::post('interviews/{recruitmentInterview}/join', [RecruitmentInterviewController::class, 'join'])->middleware('throttle:interview-join')->name('interviews.join');
    Route::post('interviews/{recruitmentInterview}/signal', [RecruitmentInterviewController::class, 'signal'])->middleware('throttle:interview-signal')->name('interviews.signal');
    Route::get('interviews/{recruitmentInterview}/messages', [RecruitmentInterviewMessageController::class, 'index'])->middleware('throttle:interview-messages-read')->name('interviews.messages.index');
    Route::post('interviews/{recruitmentInterview}/messages', [RecruitmentInterviewMessageController::class, 'store'])->middleware('throttle:interview-messages-send')->name('interviews.messages.store');
    Route::post('interviews/broadcasting/auth', [RecruitmentInterviewController::class, 'authorizeBroadcast'])->middleware('throttle:interview-broadcast-auth')->name('interviews.broadcasting.auth');
});
