<?php

use App\Http\Controllers\Api\V1\Admin\OrganizationUnitController as AdminOrganizationUnitController;
use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\JobPostingController;
use App\Http\Controllers\Api\V1\MembershipTypeController;
use App\Http\Controllers\Api\V1\OrganizationUnitController;
use App\Http\Controllers\Api\V1\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public — consumed by provatferi.org and sahittopata.provatferi.org.
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::get('/about', [SettingsController::class, 'about']);
    Route::get('/organization-units', [OrganizationUnitController::class, 'index']);
    Route::get('/organization-units/{organizationUnit}', [OrganizationUnitController::class, 'show']);
    Route::get('/activities', [ActivityController::class, 'index']);
    Route::get('/activities/{activity}', [ActivityController::class, 'show']);
    Route::get('/membership-types', [MembershipTypeController::class, 'index']);
    Route::get('/job-postings', [JobPostingController::class, 'index']);
    Route::get('/job-postings/{jobPosting}', [JobPostingController::class, 'show']);

    // Auth.
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
    });

    // Admin — Sanctum + permission-gated. Only organization-units is fully
    // implemented as the reference pattern; the others follow the same shape.
    Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
        Route::middleware('permission:organization.view')->get('/organization-units', [AdminOrganizationUnitController::class, 'index']);
        Route::middleware('permission:organization.create')->post('/organization-units', [AdminOrganizationUnitController::class, 'store']);
        Route::middleware('permission:organization.update')->put('/organization-units/{organizationUnit}', [AdminOrganizationUnitController::class, 'update']);
        Route::middleware('permission:organization.delete')->delete('/organization-units/{organizationUnit}', [AdminOrganizationUnitController::class, 'destroy']);
    });
});
