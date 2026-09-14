<?php

use App\Http\Controllers\Api\V1\Admin\OrganizationUnitController as AdminOrganizationUnitController;
use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\JobPostingController;
use App\Http\Controllers\Api\V1\Member\AuthController as MemberAuthController;
use App\Http\Controllers\Api\V1\Member\DashboardController as MemberDashboardController;
use App\Http\Controllers\Api\V1\Member\PasswordResetController as MemberPasswordResetController;
use App\Http\Controllers\Api\V1\Member\ProfileController as MemberProfileController;
use App\Http\Controllers\Api\V1\MembershipTypeController;
use App\Http\Controllers\Api\V1\OrganizationUnitController;
use App\Http\Controllers\Api\V1\Public\CommitteeController as PublicCommitteeController;
use App\Http\Controllers\Api\V1\Public\CommitteeCorrectionController;
use App\Http\Controllers\Api\V1\Public\CommitteeRegistrationController;
use App\Http\Controllers\Api\V1\Public\MemberDirectoryController;
use App\Http\Controllers\Api\V1\Public\MembershipApplicationController;
use App\Http\Controllers\Api\V1\Public\MembershipCampaignController;
use App\Http\Controllers\Api\V1\Public\NoticeController as PublicNoticeController;
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

    // Public — new-phase membership/committee contracts (§41), consumed by
    // provatferi.org's server-side fetches only, never the browser directly.
    Route::prefix('public')->group(function () {
        Route::get('/membership/campaigns/current', [MembershipCampaignController::class, 'current']);
        Route::post('/membership/applications', [MembershipApplicationController::class, 'store'])->middleware('throttle:6,1');

        Route::get('/committees', [PublicCommitteeController::class, 'index']);
        Route::get('/committees/registration-links/{token}', [CommitteeRegistrationController::class, 'show'])->middleware('throttle:20,1');
        Route::post('/committee-submissions', [CommitteeRegistrationController::class, 'store'])->middleware('throttle:6,1');
        Route::get('/committee-submissions/correction/{token}', [CommitteeCorrectionController::class, 'show'])->middleware('throttle:20,1');
        Route::post('/committee-submissions/correction/{token}', [CommitteeCorrectionController::class, 'update'])->middleware('throttle:6,1');
        Route::get('/committees/{committee}', [PublicCommitteeController::class, 'show']);

        // §13/§16: only approved+opted-in members ever appear here.
        Route::get('/members', [MemberDirectoryController::class, 'index']);
        Route::get('/members/{member}', [MemberDirectoryController::class, 'show']);

        // Notice board. /sitemap is registered before /{slug}, and 'sitemap'
        // is a reserved slug (Notice::RESERVED_SLUGS), so the two never collide.
        Route::get('/notices', [PublicNoticeController::class, 'index']);
        Route::get('/notices/sitemap', [PublicNoticeController::class, 'sitemap']);
        Route::get('/notices/{slug}', [PublicNoticeController::class, 'show']);
        Route::get('/notices/{slug}/attachment', [PublicNoticeController::class, 'attachment'])
            ->middleware('throttle:60,1')->name('api.public.notices.attachment');
        Route::get('/notices/{slug}/cover', [PublicNoticeController::class, 'cover'])
            ->middleware('throttle:120,1')->name('api.public.notices.cover');
    });

    // Auth.
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
    });

    // Member portal (§12) — completely separate from the ERP staff auth
    // above: no shared guard, no shared session, server-to-server only
    // (called from a Next.js Server Action, never the browser directly).
    Route::prefix('member')->group(function () {
        Route::post('/auth/login', [MemberAuthController::class, 'login'])->middleware('throttle:6,1');
        Route::post('/auth/password/email', [MemberPasswordResetController::class, 'sendResetLink'])->middleware('throttle:6,1');
        Route::post('/auth/password/reset', [MemberPasswordResetController::class, 'reset'])->middleware('throttle:6,1');

        Route::middleware(['auth:sanctum', 'member.auth'])->group(function () {
            Route::post('/auth/logout', [MemberAuthController::class, 'logout']);
            Route::get('/me', [MemberDashboardController::class, 'me']);
            Route::get('/profile', [MemberProfileController::class, 'show']);
            Route::post('/profile', [MemberProfileController::class, 'update']);
        });
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
