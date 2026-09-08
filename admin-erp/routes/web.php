<?php

use App\Http\Controllers\Admin\AboutController;
use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\ActivityTypeController;
use App\Http\Controllers\Admin\CommitteeController;
use App\Http\Controllers\Admin\CommitteeMemberController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\JobApplicationController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\MembershipController;
use App\Http\Controllers\Admin\MembershipTypeController;
use App\Http\Controllers\Admin\MissionController;
use App\Http\Controllers\Admin\ObjectiveController;
use App\Http\Controllers\Admin\OrganizationUnitController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\PositionController;
use App\Http\Controllers\Admin\RecruitmentController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VisionController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    return redirect()->route('admin.dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    /*
     * Organization — the complete Phase 1 CRUD reference. Static segments are
     * declared before {unit} so "create" is never captured as a model binding.
     */
    Route::prefix('organization/units')->name('organization.units.')->group(function () {
        Route::get('/', [OrganizationUnitController::class, 'index'])
            ->middleware('permission:organization.view')->name('index');

        Route::get('/create', [OrganizationUnitController::class, 'create'])
            ->middleware('permission:organization.create')->name('create');
        Route::post('/', [OrganizationUnitController::class, 'store'])
            ->middleware('permission:organization.create')->name('store');

        Route::get('/{unit}', [OrganizationUnitController::class, 'show'])
            ->middleware('permission:organization.view')->name('show');

        Route::get('/{unit}/edit', [OrganizationUnitController::class, 'edit'])
            ->middleware('permission:organization.update')->name('edit');
        Route::put('/{unit}', [OrganizationUnitController::class, 'update'])
            ->middleware('permission:organization.update')->name('update');

        Route::delete('/{unit}', [OrganizationUnitController::class, 'destroy'])
            ->middleware('permission:organization.delete')->name('destroy');
    });

    /* Positions */
    Route::prefix('organization/positions')->name('positions.')->group(function () {
        Route::get('/', [PositionController::class, 'index'])->middleware('permission:organization.view')->name('index');
        Route::get('/create', [PositionController::class, 'create'])->middleware('permission:organization.create')->name('create');
        Route::post('/', [PositionController::class, 'store'])->middleware('permission:organization.create')->name('store');
        Route::get('/{position}/edit', [PositionController::class, 'edit'])->middleware('permission:organization.update')->name('edit');
        Route::put('/{position}', [PositionController::class, 'update'])->middleware('permission:organization.update')->name('update');
        Route::delete('/{position}', [PositionController::class, 'destroy'])->middleware('permission:organization.delete')->name('destroy');
    });

    /* Committees + their members (members are always scoped to a committee) */
    Route::prefix('organization/committees')->name('committees.')->group(function () {
        Route::get('/', [CommitteeController::class, 'index'])->middleware('permission:organization.view')->name('index');
        Route::get('/create', [CommitteeController::class, 'create'])->middleware('permission:organization.create')->name('create');
        Route::post('/', [CommitteeController::class, 'store'])->middleware('permission:organization.create')->name('store');
        Route::get('/{committee}', [CommitteeController::class, 'show'])->middleware('permission:organization.view')->name('show');
        Route::get('/{committee}/edit', [CommitteeController::class, 'edit'])->middleware('permission:organization.update')->name('edit');
        Route::put('/{committee}', [CommitteeController::class, 'update'])->middleware('permission:organization.update')->name('update');
        Route::delete('/{committee}', [CommitteeController::class, 'destroy'])->middleware('permission:organization.delete')->name('destroy');

        Route::get('/{committee}/members/create', [CommitteeMemberController::class, 'create'])->middleware('permission:organization.create')->name('members.create');
        Route::post('/{committee}/members', [CommitteeMemberController::class, 'store'])->middleware('permission:organization.create')->name('members.store');
        Route::get('/{committee}/members/{member}/edit', [CommitteeMemberController::class, 'edit'])->middleware('permission:organization.update')->name('members.edit');
        Route::put('/{committee}/members/{member}', [CommitteeMemberController::class, 'update'])->middleware('permission:organization.update')->name('members.update');
        Route::delete('/{committee}/members/{member}', [CommitteeMemberController::class, 'destroy'])->middleware('permission:organization.delete')->name('members.destroy');
    });

    /* Users / Roles / Permissions — governed by the users.* permission set */
    Route::prefix('system/users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->middleware('permission:users.view')->name('index');
        Route::get('/create', [UserController::class, 'create'])->middleware('permission:users.create')->name('create');
        Route::post('/', [UserController::class, 'store'])->middleware('permission:users.create')->name('store');
        Route::get('/{user}', [UserController::class, 'show'])->middleware('permission:users.view')->name('show');
        Route::get('/{user}/edit', [UserController::class, 'edit'])->middleware('permission:users.update')->name('edit');
        Route::put('/{user}', [UserController::class, 'update'])->middleware('permission:users.update')->name('update');
        Route::patch('/{user}/status', [UserController::class, 'toggleStatus'])->middleware('permission:users.update')->name('status');
        Route::post('/{user}/password-reset', [UserController::class, 'sendPasswordReset'])->middleware('permission:users.update')->name('password-reset');
        Route::delete('/{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete')->name('destroy');
    });

    Route::prefix('system/roles')->name('roles.')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->middleware('permission:users.view')->name('index');
        Route::get('/create', [RoleController::class, 'create'])->middleware('permission:users.create')->name('create');
        Route::post('/', [RoleController::class, 'store'])->middleware('permission:users.create')->name('store');
        Route::get('/{role}/edit', [RoleController::class, 'edit'])->middleware('permission:users.update')->name('edit');
        Route::put('/{role}', [RoleController::class, 'update'])->middleware('permission:users.update')->name('update');
        Route::delete('/{role}', [RoleController::class, 'destroy'])->middleware('permission:users.delete')->name('destroy');
    });

    Route::get('/system/permissions', [PermissionController::class, 'index'])
        ->middleware('permission:users.view')->name('permissions.index');

    /* Activity Types */
    Route::prefix('activity-types')->name('activities.types.')->group(function () {
        Route::get('/', [ActivityTypeController::class, 'index'])->middleware('permission:activities.view')->name('index');
        Route::get('/create', [ActivityTypeController::class, 'create'])->middleware('permission:activities.create')->name('create');
        Route::post('/', [ActivityTypeController::class, 'store'])->middleware('permission:activities.create')->name('store');
        Route::get('/{activityType}/edit', [ActivityTypeController::class, 'edit'])->middleware('permission:activities.update')->name('edit');
        Route::put('/{activityType}', [ActivityTypeController::class, 'update'])->middleware('permission:activities.update')->name('update');
        Route::delete('/{activityType}', [ActivityTypeController::class, 'destroy'])->middleware('permission:activities.delete')->name('destroy');
    });

    /* Activities — full CRUD. Static segments before {activity} so "create" is never bound as a slug/id. */
    Route::get('/activities', [ActivityController::class, 'index'])
        ->middleware('permission:activities.view')->name('activities.index');
    Route::get('/activities/create', [ActivityController::class, 'create'])
        ->middleware('permission:activities.create')->name('activities.create');
    Route::post('/activities', [ActivityController::class, 'store'])
        ->middleware('permission:activities.create')->name('activities.store');
    Route::get('/activities/{activity}', [ActivityController::class, 'show'])
        ->middleware('permission:activities.view')->name('activities.show');
    Route::get('/activities/{activity}/edit', [ActivityController::class, 'edit'])
        ->middleware('permission:activities.update')->name('activities.edit');
    Route::put('/activities/{activity}', [ActivityController::class, 'update'])
        ->middleware('permission:activities.update')->name('activities.update');
    Route::delete('/activities/{activity}', [ActivityController::class, 'destroy'])
        ->middleware('permission:activities.delete')->name('activities.destroy');

    /* Membership Types */
    Route::prefix('membership/types')->name('membership.types.')->group(function () {
        Route::get('/', [MembershipTypeController::class, 'index'])->middleware('permission:membership.view')->name('index');
        Route::get('/create', [MembershipTypeController::class, 'create'])->middleware('permission:membership.create')->name('create');
        Route::post('/', [MembershipTypeController::class, 'store'])->middleware('permission:membership.create')->name('store');
        Route::get('/{membershipType}/edit', [MembershipTypeController::class, 'edit'])->middleware('permission:membership.update')->name('edit');
        Route::put('/{membershipType}', [MembershipTypeController::class, 'update'])->middleware('permission:membership.update')->name('update');
        Route::delete('/{membershipType}', [MembershipTypeController::class, 'destroy'])->middleware('permission:membership.delete')->name('destroy');
    });

    /*
     * Members — registered BEFORE the /membership/{membershipApplication}
     * wildcard below, or "/membership/members" would be swallowed by that
     * wildcard (Laravel matches routes in registration order) and 404 trying
     * to bind "members" as an application id. Same reasoning as static
     * segments needing to precede {unit} elsewhere in this file.
     */
    Route::prefix('membership/members')->name('membership.members.')->group(function () {
        Route::get('/', [MemberController::class, 'index'])->middleware('permission:membership.view')->name('index');
        Route::get('/{membership}', [MemberController::class, 'show'])->middleware('permission:membership.view')->name('show');
        Route::get('/{membership}/edit', [MemberController::class, 'edit'])->middleware('permission:membership.update')->name('edit');
        Route::put('/{membership}', [MemberController::class, 'update'])->middleware('permission:membership.update')->name('update');
    });

    /* Membership Applications — admin.membership.index is the existing list route, kept as-is (linked from the dashboard). */
    Route::get('/membership', [MembershipController::class, 'index'])
        ->middleware('permission:membership.view')->name('membership.index');
    Route::get('/membership/{membershipApplication}', [MembershipController::class, 'show'])
        ->middleware('permission:membership.view')->name('membership.show');
    Route::patch('/membership/{membershipApplication}/status', [MembershipController::class, 'updateStatus'])
        ->middleware('permission:membership.approve')->name('membership.status');

    /* Recruitment — Job Postings, full CRUD. admin.recruitment.index kept as-is (linked from the dashboard). */
    Route::get('/recruitment', [RecruitmentController::class, 'index'])
        ->middleware('permission:recruitment.view')->name('recruitment.index');
    Route::get('/recruitment/create', [RecruitmentController::class, 'create'])
        ->middleware('permission:recruitment.create')->name('recruitment.create');
    Route::post('/recruitment', [RecruitmentController::class, 'store'])
        ->middleware('permission:recruitment.create')->name('recruitment.store');
    Route::get('/recruitment/{jobPosting}', [RecruitmentController::class, 'show'])
        ->middleware('permission:recruitment.view')->name('recruitment.show');
    Route::get('/recruitment/{jobPosting}/edit', [RecruitmentController::class, 'edit'])
        ->middleware('permission:recruitment.update')->name('recruitment.edit');
    Route::put('/recruitment/{jobPosting}', [RecruitmentController::class, 'update'])
        ->middleware('permission:recruitment.update')->name('recruitment.update');
    Route::delete('/recruitment/{jobPosting}', [RecruitmentController::class, 'destroy'])
        ->middleware('permission:recruitment.delete')->name('recruitment.destroy');

    /* Recruitment Applications review */
    Route::prefix('recruitment-applications')->name('recruitment.applications.')->group(function () {
        Route::get('/', [JobApplicationController::class, 'index'])->middleware('permission:recruitment.view')->name('index');
        Route::get('/{jobApplication}', [JobApplicationController::class, 'show'])->middleware('permission:recruitment.view')->name('show');
        Route::patch('/{jobApplication}/status', [JobApplicationController::class, 'updateStatus'])->middleware('permission:recruitment.approve')->name('status');
    });
    Route::get('/settings', [SettingsController::class, 'index'])
        ->middleware('permission:settings.view')->name('settings.index');
    Route::put('/settings', [SettingsController::class, 'update'])
        ->middleware('permission:settings.update')->name('settings.update');

    /* Institutional content — About/Mission/Vision are singletons; Objectives is a full CRUD list. */
    Route::get('/content/about', [AboutController::class, 'edit'])
        ->middleware('permission:settings.view')->name('content.about.edit');
    Route::put('/content/about', [AboutController::class, 'update'])
        ->middleware('permission:settings.update')->name('content.about.update');

    Route::get('/content/mission', [MissionController::class, 'edit'])
        ->middleware('permission:settings.view')->name('content.mission.edit');
    Route::put('/content/mission', [MissionController::class, 'update'])
        ->middleware('permission:settings.update')->name('content.mission.update');

    Route::get('/content/vision', [VisionController::class, 'edit'])
        ->middleware('permission:settings.view')->name('content.vision.edit');
    Route::put('/content/vision', [VisionController::class, 'update'])
        ->middleware('permission:settings.update')->name('content.vision.update');

    Route::prefix('content/objectives')->name('content.objectives.')->group(function () {
        Route::get('/', [ObjectiveController::class, 'index'])
            ->middleware('permission:settings.view')->name('index');
        Route::get('/create', [ObjectiveController::class, 'create'])
            ->middleware('permission:settings.create')->name('create');
        Route::post('/', [ObjectiveController::class, 'store'])
            ->middleware('permission:settings.create')->name('store');
        Route::get('/{objective}/edit', [ObjectiveController::class, 'edit'])
            ->middleware('permission:settings.update')->name('edit');
        Route::put('/{objective}', [ObjectiveController::class, 'update'])
            ->middleware('permission:settings.update')->name('update');
        Route::patch('/{objective}/toggle', [ObjectiveController::class, 'toggleActive'])
            ->middleware('permission:settings.update')->name('toggle');
        Route::post('/{objective}/move-up', [ObjectiveController::class, 'moveUp'])
            ->middleware('permission:settings.update')->name('move-up');
        Route::post('/{objective}/move-down', [ObjectiveController::class, 'moveDown'])
            ->middleware('permission:settings.update')->name('move-down');
        Route::delete('/{objective}', [ObjectiveController::class, 'destroy'])
            ->middleware('permission:settings.delete')->name('destroy');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
