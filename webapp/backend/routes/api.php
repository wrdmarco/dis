<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminPushController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CertificationController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DispatchController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MobileConfigController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\UserController;
use App\Http\Responses\ApiResponse;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Broadcast::routes(['middleware' => ['auth:sanctum', 'operational']]);

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/auth/password/forgot', [PasswordController::class, 'forgot'])->middleware('throttle:password-reset');
Route::post('/auth/password/reset', [PasswordController::class, 'reset'])->middleware('throttle:password-reset');
Route::get('/setup/status', [SetupController::class, 'status'])->middleware('throttle:api');
Route::post('/setup/complete', [SetupController::class, 'complete'])->middleware('throttle:login');
Route::get('/mobile/config', [MobileConfigController::class, 'show'])->middleware('throttle:mobile-public');
Route::get('/updates/android/current', [UpdateController::class, 'androidCurrent'])->middleware('throttle:mobile-public');
Route::get('/updates/android/{version}/download', [UpdateController::class, 'downloadAndroid'])->middleware('throttle:mobile-public');
Route::get('/health', [HealthController::class, 'public'])->middleware('throttle:api');

Route::middleware(['auth:sanctum', 'operational', 'audit.privileged'])->group(function (): void {
    Route::post('/auth/2fa/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:two-factor');
    Route::post('/auth/2fa/setup', [AuthController::class, 'setupTwoFactor'])->middleware('throttle:two-factor');
    Route::post('/auth/2fa/enable', [AuthController::class, 'enableTwoFactor'])->middleware('throttle:two-factor');
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::middleware('two_factor.complete')->group(function (): void {
        Route::post('/auth/2fa/disable', [AuthController::class, 'disableTwoFactor'])->middleware('throttle:two-factor');

        Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.manage');
        Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
        Route::patch('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.manage');
        Route::post('/users/{user}/roles', [UserController::class, 'assignRole'])->middleware('permission:roles.manage');
        Route::delete('/users/{user}/roles/{role}', [UserController::class, 'removeRole'])->middleware('permission:roles.manage');
        Route::post('/users/{user}/teams', [UserController::class, 'assignTeam'])->middleware('permission:teams.manage');
        Route::delete('/users/{user}/teams/{team}', [UserController::class, 'removeTeam'])->middleware('permission:teams.manage');
        Route::get('/users/{user}/audit', [UserController::class, 'audit'])->middleware('permission:audit.view');

    Route::get('/teams', [AdminController::class, 'teams'])->middleware('permission:incidents.view');

    Route::get('/incidents', [IncidentController::class, 'index'])->middleware('permission:incidents.view');
    Route::post('/incidents', [IncidentController::class, 'store'])->middleware('permission:incidents.manage');
    Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->middleware('permission:incidents.view');
    Route::patch('/incidents/{incident}', [IncidentController::class, 'update'])->middleware('permission:incidents.manage');
    Route::post('/incidents/{incident}/close', [IncidentController::class, 'close'])->middleware('permission:incidents.manage');
    Route::post('/incidents/{incident}/cancel', [IncidentController::class, 'cancel'])->middleware('permission:incidents.manage');
    Route::get('/incidents/{incident}/timeline', [IncidentController::class, 'timeline'])->middleware('permission:incidents.view');
    Route::post('/incidents/{incident}/location/consent', [LocationController::class, 'consent'])->middleware('permission:incidents.view');
    Route::delete('/incidents/{incident}/location/consent', [LocationController::class, 'revoke'])->middleware('permission:incidents.view');
    Route::post('/incidents/{incident}/location', [LocationController::class, 'update'])->middleware('permission:incidents.view');
    Route::post('/incidents/{incident}/dispatches', [DispatchController::class, 'store'])->middleware('permission:dispatch.manage');

    Route::get('/dispatches', [DispatchController::class, 'index'])->middleware('permission:dispatch.view');
    Route::get('/dispatches/{dispatch}', [DispatchController::class, 'show'])->middleware('permission:dispatch.view');
    Route::post('/dispatches/{dispatch}/send', [DispatchController::class, 'send'])->middleware('permission:dispatch.manage');
    Route::post('/dispatches/{dispatch}/respond', [DispatchController::class, 'respond'])->middleware('throttle:dispatch-response');
    Route::post('/dispatches/{dispatch}/cancel', [DispatchController::class, 'cancel'])->middleware('permission:dispatch.manage');
    Route::post('/dispatches/{dispatch}/escalate', [DispatchController::class, 'escalate'])->middleware('permission:dispatch.manage');
    Route::get('/dispatches/{dispatch}/recipients', [DispatchController::class, 'recipients'])->middleware('permission:dispatch.view');

    Route::get('/status/me', [StatusController::class, 'me']);
    Route::patch('/status/me', [StatusController::class, 'updateMe']);
    Route::get('/status/users', [StatusController::class, 'users'])->middleware('permission:status.view');
    Route::post('/status/users/{user}/override', [StatusController::class, 'override'])->middleware('permission:status.override');
    Route::get('/status/history', [StatusController::class, 'history'])->middleware('permission:status.view');

    Route::get('/assets', [AssetController::class, 'index'])->middleware('permission:assets.view');
    Route::post('/assets', [AssetController::class, 'store'])->middleware('permission:assets.manage');
    Route::get('/assets/{asset}', [AssetController::class, 'show'])->middleware('permission:assets.view');
    Route::patch('/assets/{asset}', [AssetController::class, 'update'])->middleware('permission:assets.manage');
    Route::post('/assets/{asset}/assign', [AssetController::class, 'assign'])->middleware('permission:assets.manage');
    Route::post('/assets/{asset}/release', [AssetController::class, 'release'])->middleware('permission:assets.manage');
    Route::get('/assets/{asset}/history', [AssetController::class, 'history'])->middleware('permission:assets.view');

    Route::get('/certifications', [CertificationController::class, 'index'])->middleware('permission:certifications.view');
    Route::post('/certifications', [CertificationController::class, 'store'])->middleware('permission:certifications.manage');
    Route::patch('/certifications/{certification}', [CertificationController::class, 'update'])->middleware('permission:certifications.manage');
    Route::get('/users/{user}/certifications', [CertificationController::class, 'userCertifications'])->middleware('permission:certifications.view');
    Route::post('/users/{user}/certifications', [CertificationController::class, 'assignToUser'])->middleware('permission:certifications.manage');
    Route::patch('/users/{user}/certifications/{userCertification}', [CertificationController::class, 'updateUserCertification'])->middleware('permission:certifications.manage');
    Route::delete('/users/{user}/certifications/{userCertification}', [CertificationController::class, 'revokeUserCertification'])->middleware('permission:certifications.manage');

    Route::post('/devices/fcm-token', [DeviceController::class, 'register'])->middleware('throttle:push-token');
    Route::delete('/devices/fcm-token/{token}', [DeviceController::class, 'revoke']);
    Route::get('/devices', [DeviceController::class, 'index']);

    Route::get('/admin/roles', [AdminController::class, 'roles'])->middleware('permission:roles.manage');
    Route::post('/admin/roles', [AdminController::class, 'storeRole'])->middleware('permission:roles.manage');
    Route::patch('/admin/roles/{role}', [AdminController::class, 'updateRole'])->middleware('permission:roles.manage');
    Route::get('/admin/permissions', [AdminController::class, 'permissions'])->middleware('permission:roles.manage');
    Route::get('/admin/teams', [AdminController::class, 'teams'])->middleware('permission:teams.manage');
    Route::post('/admin/teams', [AdminController::class, 'storeTeam'])->middleware('permission:teams.manage');
    Route::patch('/admin/teams/{team}', [AdminController::class, 'updateTeam'])->middleware('permission:teams.manage');
    Route::get('/admin/audit-logs', [AdminController::class, 'auditLogs'])->middleware('permission:audit.view');
    Route::get('/admin/settings', [AdminController::class, 'settings'])->middleware('permission:settings.manage');
    Route::patch('/admin/settings', [AdminController::class, 'updateSettings'])->middleware('permission:settings.manage');
    Route::get('/admin/push/logs', [AdminController::class, 'pushLogs'])->middleware('permission:push.manage');
    Route::get('/admin/push/tokens', [AdminPushController::class, 'tokens'])->middleware('permission:push.manage');
    Route::post('/admin/push/tokens/{token}/revoke', [AdminPushController::class, 'revoke'])->middleware('permission:push.manage');
    Route::post('/admin/push/tokens/{token}/activate', [AdminPushController::class, 'activate'])->middleware('permission:push.manage');
    Route::post('/admin/push/manual', [AdminPushController::class, 'send'])->middleware('permission:push.manage');

    Route::get('/admin/updates/android', [UpdateController::class, 'index'])->middleware('permission:updates.manage');
    Route::post('/admin/updates/android', [UpdateController::class, 'store'])->middleware('permission:updates.manage');
    Route::post('/admin/updates/android/upload', [UpdateController::class, 'uploadAndroid'])->middleware('permission:updates.manage');
    Route::patch('/admin/updates/android/{version}', [UpdateController::class, 'update'])->middleware('permission:updates.manage');
    Route::get('/admin/health', [HealthController::class, 'admin'])->middleware('permission:system.health');
    Route::get('/admin/queues', [HealthController::class, 'queues'])->middleware('permission:system.health');
    Route::get('/admin/websocket-status', [HealthController::class, 'websocket'])->middleware('permission:system.health');
    });
});

Route::fallback(fn () => ApiResponse::error('api_route_not_found', 'DIS API route was not found.', 404));
