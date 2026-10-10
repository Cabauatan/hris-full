<?php

use App\Http\Controllers\Api\V1\ApplicantApplicationController;
use App\Http\Controllers\Api\V1\ApplicantExamAttemptController;
use App\Http\Controllers\Api\V1\CareerJobOpeningController;
use App\Http\Controllers\Api\V1\JobApplicationController;
use App\Http\Controllers\Api\V1\JobOpeningController;
use App\Http\Controllers\Api\V1\RecruitmentExamController;
use App\Http\Middleware\EnsureApplicantIsActive;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleApiBusinessExceptions;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/careers')->name('api.v1.careers.')->middleware('throttle:60,1')->group(function (): void {
    Route::get('job-openings', [CareerJobOpeningController::class, 'index'])->name('job-openings.index');
    Route::get('job-openings/{jobOpening}', [CareerJobOpeningController::class, 'show'])->name('job-openings.show');
});

Route::prefix('v1/applicant')->name('api.v1.applicant.')->middleware([
    'web', 'auth:applicant', 'auth.session', EnsureApplicantIsActive::class, HandleApiBusinessExceptions::class,
])->group(function (): void {
    Route::get('applications', [ApplicantApplicationController::class, 'index'])->name('applications.index');
    Route::post('applications', [ApplicantApplicationController::class, 'store'])->name('applications.store');
    Route::get('applications/{jobApplication}', [ApplicantApplicationController::class, 'show'])->name('applications.show');
    Route::get('exams/{applicantExamAttempt}', [ApplicantExamAttemptController::class, 'show'])->name('exams.show');
    Route::post('exams/{applicantExamAttempt}/submit', [ApplicantExamAttemptController::class, 'submit'])->name('exams.submit');
});

Route::prefix('v1/recruitment')->name('api.v1.recruitment.')->middleware([
    'web', 'auth:web', 'auth.session', EnsureUserIsActive::class, HandleApiBusinessExceptions::class,
])->group(function (): void {
    Route::get('job-openings', [JobOpeningController::class, 'index'])->name('job-openings.index');
    Route::post('job-openings', [JobOpeningController::class, 'store'])->name('job-openings.store');
    Route::get('job-openings/{jobOpening}', [JobOpeningController::class, 'show'])->name('job-openings.show');
    Route::put('job-openings/{jobOpening}', [JobOpeningController::class, 'update'])->name('job-openings.update');
    Route::get('applications', [JobApplicationController::class, 'index'])->name('applications.index');
    Route::get('applications/{jobApplication}', [JobApplicationController::class, 'show'])->name('applications.show');
    Route::post('applications/{jobApplication}/approve', [JobApplicationController::class, 'approve'])->name('applications.approve');
    Route::post('applications/{jobApplication}/reject', [JobApplicationController::class, 'reject'])->name('applications.reject');
    Route::post('applications/{jobApplication}/hire', [JobApplicationController::class, 'hire'])->name('applications.hire');
    Route::post('applications/{jobApplication}/exams', [JobApplicationController::class, 'assign'])->name('applications.exams.assign');
    Route::get('exams', [RecruitmentExamController::class, 'index'])->name('exams.index');
    Route::post('exams', [RecruitmentExamController::class, 'store'])->name('exams.store');
    Route::get('exams/{recruitmentExam}', [RecruitmentExamController::class, 'show'])->name('exams.show');
    Route::get('attempts/{applicantExamAttempt}', [ApplicantExamAttemptController::class, 'show'])->name('attempts.show');
    Route::post('attempts/{applicantExamAttempt}/grade', [ApplicantExamAttemptController::class, 'grade'])->name('attempts.grade');
});
