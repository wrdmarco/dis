<?php

use App\Http\Controllers\AddressBookController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminDeveloperController;
use App\Http\Controllers\AdminKnmiController;
use App\Http\Controllers\AdminOsrmController;
use App\Http\Controllers\AdminPushController;
use App\Http\Controllers\AdminSpeechController;
use App\Http\Controllers\AdminStoreReviewController;
use App\Http\Controllers\AdminWallboardController;
use App\Http\Controllers\AdminWallboardMediaAssetController;
use App\Http\Controllers\AdminWallboardMediaFolderController;
use App\Http\Controllers\AdminWallboardMediaPlaylistController;
use App\Http\Controllers\AdminWallboardNewsImageController;
use App\Http\Controllers\AdminWallboardPlaylistController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvailabilityScheduleController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\CalendarEventController;
use App\Http\Controllers\CertificationController;
use App\Http\Controllers\DeveloperDispatchDiagnosticsController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DispatchController;
use App\Http\Controllers\DroneTypeController;
use App\Http\Controllers\ExpiryOverviewController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\IncidentFormController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MobileConfigController;
use App\Http\Controllers\MobilePairingController;
use App\Http\Controllers\OperationalForecastController;
use App\Http\Controllers\OperationalMapController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PilotIncidentReportController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\ReportingController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\StatusAuditController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\TestAlertController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VacationController;
use App\Http\Controllers\WallboardController;
use App\Http\Controllers\WallboardMediaController;
use App\Http\Controllers\WallboardPairingController;
use App\Http\Responses\ApiResponse;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Broadcast::routes(['middleware' => ['auth:sanctum', 'web.session', 'operational', 'two_factor.complete', 'throttle:alarm-read']]);

Route::get('/auth/csrf-cookie', [AuthController::class, 'csrfCookie'])->middleware('throttle:api');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/wallboard/pairing/start', [WallboardPairingController::class, 'start'])->middleware('throttle:wallboard-pairing-start');
Route::post('/wallboard/pairing/status', [WallboardPairingController::class, 'status'])->middleware('throttle:wallboard-pairing-status');
Route::get('/wallboard/state', [WallboardController::class, 'state'])->middleware(['wallboard.auth', 'throttle:wallboard-read']);
Route::get('/wallboard/live', [WallboardController::class, 'live'])->middleware(['wallboard.auth', 'throttle:wallboard-read']);
Route::get('/wallboard/static', [WallboardController::class, 'staticContent'])
    ->middleware(['wallboard.auth', 'throttle:wallboard-read'])
    ->name('wallboard.static');
Route::get('/wallboard/news', [WallboardController::class, 'news'])
    ->middleware(['wallboard.auth', 'throttle:wallboard-read'])
    ->name('wallboard.news');
Route::get('/wallboard/ticker', [WallboardController::class, 'ticker'])
    ->middleware(['wallboard.auth', 'throttle:wallboard-read'])
    ->name('wallboard.ticker');
Route::get('/wallboard/control', [WallboardController::class, 'control'])->middleware(['wallboard.auth', 'throttle:wallboard-control']);
Route::get('/wallboard/cache', [WallboardController::class, 'clearCache'])
    ->middleware(['wallboard.auth', 'throttle:wallboard-read'])
    ->name('wallboard.cache');
Route::get('/wallboard/news-images/{image}', [WallboardController::class, 'newsImage'])
    ->where('image', '[a-f0-9]{64}')
    ->middleware(['wallboard.auth', 'throttle:wallboard-read']);
Route::get('/wallboard/media/{asset}', [WallboardMediaController::class, 'content'])
    ->whereUlid('asset')
    ->middleware(['wallboard.auth', 'throttle:wallboard-media-read'])
    ->name('wallboard-media.content');
Route::get('/wallboard/weather-radar/{kind}/{snapshot}.png', [WallboardController::class, 'weatherRadarAtlas'])
    ->where('kind', 'precipitation|lightning')
    ->where('snapshot', '\\d{8}T\\d{6}Z-[a-f0-9]{16}')
    ->middleware(['wallboard.auth', 'throttle:wallboard-media-read'])
    ->name('wallboard.weather-radar-atlas');
Route::post('/auth/mobile-pairing/consume', [MobilePairingController::class, 'consume'])->middleware('throttle:mobile-pairing');
Route::post('/auth/password/forgot', [PasswordController::class, 'forgot'])->middleware('throttle:password-reset');
Route::post('/auth/password/reset', [PasswordController::class, 'reset'])->middleware('throttle:password-reset');
Route::post('/registration/invite', [RegistrationController::class, 'show'])->middleware('throttle:password-reset');
Route::post('/registration/complete', [RegistrationController::class, 'complete'])->middleware('throttle:password-reset');
Route::post('/registration/mobile-pairing', [RegistrationController::class, 'mobilePairing'])->middleware('throttle:mobile-pairing');
Route::get('/setup/status', [SetupController::class, 'status'])->middleware('throttle:api');
Route::post('/setup/complete', [SetupController::class, 'complete'])->middleware('throttle:setup');
Route::get('/mobile/config', [MobileConfigController::class, 'show'])->middleware('throttle:mobile-public');
Route::get('/updates/android/current', [UpdateController::class, 'androidCurrent'])->middleware('throttle:mobile-public');
Route::get('/updates/android/{version}/download', [UpdateController::class, 'downloadAndroid'])->middleware('throttle:mobile-public');
Route::get('/updates/ios/current', [UpdateController::class, 'iosCurrent'])->middleware('throttle:mobile-public');
Route::get('/branding', [BrandingController::class, 'show'])->middleware('throttle:api');
Route::post('/developer/android/upload', [UpdateController::class, 'developerUploadAndroid'])->middleware('throttle:developer-upload');
Route::post('/developer/system/maintenance', [AdminDeveloperController::class, 'developerMaintenance'])->middleware('throttle:developer-update');
Route::post('/developer/system/update', [AdminDeveloperController::class, 'developerRunUpdate'])->middleware('throttle:developer-update');
Route::post('/developer/users/login-lock/reset', [AdminDeveloperController::class, 'developerResetLoginLock'])->middleware('throttle:developer-update');
Route::get('/developer/logs', [AdminDeveloperController::class, 'developerLogs'])->middleware('throttle:developer-logs');
Route::get('/developer/logs/{filename}', [AdminDeveloperController::class, 'developerLog'])->where('filename', '[A-Za-z0-9._-]+\.log')->middleware('throttle:developer-logs');
Route::get('/developer/dispatches/{dispatchId}/diagnostics', [DeveloperDispatchDiagnosticsController::class, 'show'])->middleware('throttle:developer-logs');
Route::get('/developer/incidents/{incidentId}/dispatches', [DeveloperDispatchDiagnosticsController::class, 'indexForIncident'])->middleware('throttle:developer-logs');
Route::get('/health', [HealthController::class, 'public'])->middleware('throttle:api');

Route::middleware(['two_factor.challenge', 'operational', 'audit.privileged', 'store.review'])->group(function (): void {
    Route::post('/auth/2fa/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:two-factor');
    Route::post('/auth/2fa/setup', [AuthController::class, 'setupTwoFactor'])->middleware('throttle:two-factor');
    Route::post('/auth/2fa/enable', [AuthController::class, 'enableTwoFactor'])->middleware('throttle:two-factor');
});

Route::middleware(['auth:sanctum', 'web.session', 'operational', 'audit.privileged', 'store.review', 'throttle:authenticated'])->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware('two_factor.complete');
    Route::post('/auth/session/touch', [AuthController::class, 'touchSession'])->middleware('two_factor.complete');
    Route::patch('/auth/me', [AuthController::class, 'updateMe'])->middleware('two_factor.complete');

    Route::middleware('two_factor.complete')->group(function (): void {
        Route::post('/auth/2fa/disable', [AuthController::class, 'disableTwoFactor'])->middleware('throttle:two-factor');
        Route::get('/software/download-options', [UpdateController::class, 'downloadOptions']);
        Route::post('/auth/mobile-pairing', [MobilePairingController::class, 'create'])->middleware('throttle:api');

        Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.manage');
        Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
        Route::patch('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.manage');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete');
        Route::post('/users/{user}/roles', [UserController::class, 'assignRole'])->middleware('permission:roles.manage');
        Route::delete('/users/{user}/roles/{role}', [UserController::class, 'removeRole'])->middleware('permission:roles.manage');
        Route::post('/users/{user}/teams', [UserController::class, 'assignTeam'])->middleware('permission:teams.manage');
        Route::delete('/users/{user}/teams/{team}', [UserController::class, 'removeTeam'])->middleware('permission:teams.manage');
        Route::post('/users/{user}/2fa/reset', [UserController::class, 'resetTwoFactor'])->middleware('permission:users.mfa.reset');
        Route::post('/users/{user}/login-lock/reset', [UserController::class, 'resetLoginLock'])->middleware('permission:users.login-lock.reset');
        Route::post('/users/{user}/sessions/revoke', [UserController::class, 'revokeSessions'])->middleware('permission:users.sessions.revoke');
        Route::post('/users/{user}/invitation/resend', [UserController::class, 'resendInvitation'])->middleware('permission:users.manage');
        Route::post('/users/{user}/password-recovery/send', [UserController::class, 'sendPasswordRecovery'])->middleware(['permission:users.credentials.manage', 'throttle:password-reset']);
        Route::get('/users/{user}/audit', [UserController::class, 'audit'])->middleware('permission:audit.view');

        Route::get('/address-book', [AddressBookController::class, 'index'])->middleware('permission:address-book.view');

        Route::get('/teams', [AdminController::class, 'teams'])->middleware('permission:incidents.view');

        Route::get('/test-alert', [TestAlertController::class, 'show'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.dispatch.view,incidents.dispatch.manage', 'throttle:alarm-read']);
        Route::post('/test-alert', [TestAlertController::class, 'send'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.dispatch.manage', 'throttle:reachability-test']);
        Route::get('/test-alert/schedule', [TestAlertController::class, 'schedule'])->middleware('permission:incidents.dispatch.manage');
        Route::patch('/test-alert/schedule', [TestAlertController::class, 'updateSchedule'])->middleware('permission:incidents.dispatch.manage');

        Route::get('/incidents', [IncidentController::class, 'index'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.view,incidents.assigned.view', 'throttle:alarm-read']);
        Route::get('/operational-map/layers', [OperationalMapController::class, 'layers'])->middleware('permission:operational-map.view');
        Route::post('/incidents', [IncidentController::class, 'store'])->middleware('permission:incidents.manage');
        Route::get('/incident-form/config', [IncidentFormController::class, 'show'])->middleware('permission:incidents.view');
        Route::post('/incidents/flight-context-preview', [IncidentController::class, 'flightContextPreview'])->middleware('permission:incidents.manage');
        Route::get('/incidents/{incident}', [IncidentController::class, 'show'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.view,incidents.assigned.view', 'throttle:alarm-read']);
        Route::patch('/incidents/{incident}', [IncidentController::class, 'update'])->middleware('permission:incidents.manage');
        Route::get('/incidents/{incident}/internal-notes', [IncidentController::class, 'internalNotes'])->middleware('permission:incidents.manage');
        Route::patch('/incidents/{incident}/internal-notes', [IncidentController::class, 'updateInternalNotes'])->middleware('permission:incidents.manage');
        Route::delete('/incidents/{incident}', [IncidentController::class, 'destroy'])->middleware('permission:incidents.delete');
        Route::post('/incidents/{incident}/flight-context/refresh', [IncidentController::class, 'refreshFlightContext'])->middleware('permission:incidents.manage');
        Route::post('/incidents/{incident}/close', [IncidentController::class, 'close'])->middleware('permission:incidents.manage');
        Route::post('/incidents/{incident}/cancel', [IncidentController::class, 'cancel'])->middleware('permission:incidents.manage');
        Route::get('/incidents/{incident}/timeline', [IncidentController::class, 'timeline'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.view,incidents.assigned.view', 'throttle:alarm-read']);
        Route::get('/pilot-report/form-config', [PilotIncidentReportController::class, 'formConfig'])->middleware('permission:incidents.view,incidents.assigned.view');
        Route::get('/incidents/{incident}/pilot-report', [PilotIncidentReportController::class, 'show'])->middleware('permission:incidents.view,incidents.assigned.view');
        Route::patch('/incidents/{incident}/pilot-report', [PilotIncidentReportController::class, 'update'])->middleware('permission:incidents.view,incidents.assigned.view');
        Route::post('/incidents/{incident}/pilot-report/finalize', [PilotIncidentReportController::class, 'finalize'])->middleware('permission:incidents.view,incidents.assigned.view');
        Route::get('/incidents/{incident}/pilot-reports/{user}', [PilotIncidentReportController::class, 'showForUser'])->middleware('permission:incidents.manage');
        Route::patch('/incidents/{incident}/pilot-reports/{user}', [PilotIncidentReportController::class, 'updateForUser'])->middleware('permission:incidents.manage');
        Route::post('/incidents/{incident}/pilot-reports/{user}/finalize', [PilotIncidentReportController::class, 'finalizeForUser'])->middleware('permission:incidents.manage');
        Route::get('/incidents/{incidentId}/report', [ReportingController::class, 'incidentPdf'])->middleware('permission:incidents.view');
        Route::get('/incidents/{incidentId}/report.pdf', [ReportingController::class, 'incidentPdf'])->middleware('permission:incidents.view');
        Route::get('/incidents/{incident}/dispatch-preview', [IncidentController::class, 'dispatchPreview'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.dispatch.view', 'throttle:alarm-read']);
        Route::get('/incidents/{incident}/dispatches', [DispatchController::class, 'incidentDispatches'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.dispatch.view,incidents.assigned.view', 'throttle:alarm-read']);
        Route::get('/incidents/{incident}/live-locations', [LocationController::class, 'liveLocations'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.view,incidents.assigned.view', 'throttle:alarm-read']);
        Route::post('/incidents/{incident}/location/request', [LocationController::class, 'requestSharing'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.dispatch.manage', 'throttle:alarm-dispatch']);
        Route::post('/incidents/{incident}/location/consent', [LocationController::class, 'consent'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.view,incidents.assigned.view', 'throttle:alarm-response']);
        Route::post('/incidents/{incident}/location/decline', [LocationController::class, 'decline'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.view,incidents.assigned.view', 'throttle:alarm-response']);
        Route::delete('/incidents/{incident}/location/consent', [LocationController::class, 'revoke'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.view,incidents.assigned.view', 'throttle:alarm-response']);
        Route::post('/incidents/{incident}/location', [LocationController::class, 'update'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.view,incidents.assigned.view', 'throttle:operational-telemetry']);
        Route::post('/incidents/{incident}/dispatches', [DispatchController::class, 'store'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.dispatch.manage', 'throttle:alarm-dispatch']);

        Route::get('/dispatches', [DispatchController::class, 'index'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.dispatch.view,incidents.assigned.view', 'throttle:alarm-read']);
        Route::get('/dispatches/{dispatch}', [DispatchController::class, 'show'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.dispatch.view,incidents.assigned.view', 'throttle:alarm-read']);
        Route::get('/dispatches/{dispatch}/delivery', [DispatchController::class, 'delivery'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.dispatch.view,incidents.assigned.view', 'throttle:alarm-read']);
        Route::post('/dispatches/{dispatch}/send', [DispatchController::class, 'send'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.dispatch.manage', 'throttle:alarm-dispatch']);
        Route::post('/dispatches/{dispatch}/message', [DispatchController::class, 'message'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.dispatch.manage', 'throttle:alarm-dispatch']);
        Route::post('/dispatches/{dispatch}/respond', [DispatchController::class, 'respond'])->middleware([
            'permission:incidents.assigned.view,incidents.dispatch.view,incidents.dispatch.manage',
            'throttle:alarm-response',
        ])->withoutMiddleware('throttle:authenticated');
        Route::patch('/dispatches/{dispatch}/recipients/{recipient}/response', [DispatchController::class, 'overrideRecipientResponse'])->middleware('permission:incidents.dispatch.manage');
        Route::post('/dispatches/{dispatch}/cancel', [DispatchController::class, 'cancel'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.dispatch.manage', 'throttle:alarm-dispatch']);
        Route::post('/dispatches/{dispatch}/escalate', [DispatchController::class, 'escalate'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.dispatch.manage', 'throttle:alarm-dispatch']);
        Route::post('/dispatches/{dispatch}/re-alert', [DispatchController::class, 'reAlert'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.dispatch.manage', 'throttle:alarm-dispatch']);
        Route::get('/dispatches/{dispatch}/recipients', [DispatchController::class, 'recipients'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware(['permission:incidents.dispatch.view,incidents.assigned.view', 'throttle:alarm-read']);
        Route::get('/speech/manifests/{manifest}/audio', [AdminSpeechController::class, 'manifestAudio'])
            ->whereUlid('manifest')
            ->withoutMiddleware('throttle:authenticated')
            ->middleware('throttle:alarm-read');
        Route::get('/reports/incidents', [ReportingController::class, 'incidents'])->middleware('permission:incidents.view');
        Route::get('/reports/dispatch-statistics', [ReportingController::class, 'dispatchStatistics'])->middleware('permission:incidents.dispatch.view');
        Route::get('/expiry-overview', [ExpiryOverviewController::class, 'index']);
        Route::get('/calendar-events', [CalendarEventController::class, 'index']);
        Route::get('/operational-weather', [OperationalForecastController::class, 'weather'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware('throttle:operational-forecast-read');
        Route::get('/operational-weather/radar/{kind}/{snapshot}.png', [OperationalForecastController::class, 'radarAtlas'])
            ->where('kind', 'precipitation|lightning')
            ->where('snapshot', '\\d{8}T\\d{6}Z-[a-f0-9]{16}')
            ->withoutMiddleware('throttle:authenticated')
            ->middleware('throttle:operational-radar-read')
            ->name('operational-weather.radar-atlas');
        Route::get('/uav-forecast', [OperationalForecastController::class, 'uav'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware('throttle:operational-forecast-read');
        Route::post('/calendar-events', [CalendarEventController::class, 'store'])->middleware('permission:settings.manage');
        Route::delete('/calendar-events/{calendarEvent}', [CalendarEventController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('/status/me', [StatusController::class, 'me'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware('throttle:alarm-read');
        Route::patch('/status/me', [StatusController::class, 'updateMe'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware('throttle:operational-action');
        Route::get('/availability-schedule/me', [AvailabilityScheduleController::class, 'mine']);
        Route::patch('/availability-schedule/me/week-pattern', [AvailabilityScheduleController::class, 'updateMine']);
        Route::post('/availability-schedule/me/overrides', [AvailabilityScheduleController::class, 'storeMineOverride']);
        Route::delete('/availability-schedule/overrides/{override}', [AvailabilityScheduleController::class, 'deleteOverride']);
        Route::get('/availability-statuses/users', [StatusController::class, 'users'])->middleware('permission:status.view');
        Route::post('/availability-statuses/users/{user}/override', [StatusController::class, 'override'])->middleware('permission:status.override');
        Route::get('/availability-statuses/users/{user}/availability-schedule', [AvailabilityScheduleController::class, 'show'])->middleware('permission:status.view');
        Route::patch('/availability-statuses/users/{user}/availability-schedule/week-pattern', [AvailabilityScheduleController::class, 'updateForUser'])->middleware('permission:status.override');
        Route::post('/availability-statuses/users/{user}/availability-schedule/overrides', [AvailabilityScheduleController::class, 'storeUserOverride'])->middleware('permission:status.override');
        Route::get('/status/history', [StatusController::class, 'history'])->middleware('permission:status.view');
        Route::get('/status/audit', [StatusAuditController::class, 'index'])->middleware('permission:status.audit.view');
        Route::get('/vacations/mine', [VacationController::class, 'mine']);
        Route::post('/vacations/mine', [VacationController::class, 'store']);
        Route::delete('/vacations/{vacation}', [VacationController::class, 'cancel']);
        Route::get('/vacations', [VacationController::class, 'index'])->middleware('permission:status.view');
        Route::get('/users/{user}/vacations', [VacationController::class, 'userVacations'])->middleware('permission:users.view');
        Route::post('/users/{user}/vacations', [VacationController::class, 'storeForUser'])->middleware('permission:users.manage');

        Route::get('/assets/mine', [AssetController::class, 'mine']);
        Route::post('/assets/mine', [AssetController::class, 'storeMine']);
        Route::patch('/assets/{asset}/mine', [AssetController::class, 'updateMine']);
        Route::delete('/assets/{asset}/mine', [AssetController::class, 'destroyMine']);
        Route::get('/drone-types', [DroneTypeController::class, 'index']);
        Route::get('/assets', [AssetController::class, 'index'])->middleware('permission:assets.view');
        Route::post('/assets', [AssetController::class, 'store'])->middleware('permission:assets.manage');
        Route::get('/assets/{asset}', [AssetController::class, 'show'])->middleware('permission:assets.view');
        Route::patch('/assets/{asset}', [AssetController::class, 'update'])->middleware('permission:assets.manage');
        Route::delete('/assets/{asset}', [AssetController::class, 'destroy'])->middleware('permission:assets.manage');
        Route::post('/assets/{asset}/assign', [AssetController::class, 'assign'])->middleware('permission:assets.manage');
        Route::post('/assets/{asset}/release', [AssetController::class, 'release'])->middleware('permission:assets.manage');
        Route::get('/assets/{asset}/history', [AssetController::class, 'history'])->middleware('permission:assets.view');
        Route::post('/admin/drone-types', [DroneTypeController::class, 'store'])->middleware('permission:assets.manage');
        Route::patch('/admin/drone-types/{droneType}', [DroneTypeController::class, 'update'])->middleware('permission:assets.manage');
        Route::delete('/admin/drone-types/{droneType}', [DroneTypeController::class, 'destroy'])->middleware('permission:assets.manage');

        Route::get('/certifications', [CertificationController::class, 'index']);
        Route::get('/certifications/options', [CertificationController::class, 'options']);
        Route::get('/certifications/me', [CertificationController::class, 'myCertifications']);
        Route::post('/certifications/me', [CertificationController::class, 'storeMyCertification']);
        Route::patch('/certifications/me/{userCertification}', [CertificationController::class, 'updateMyCertification']);
        Route::delete('/certifications/me/{userCertification}', [CertificationController::class, 'deleteMyCertification']);
        Route::post('/certifications', [CertificationController::class, 'store'])->middleware('permission:certifications.manage');
        Route::patch('/certifications/{certification}', [CertificationController::class, 'update'])->middleware('permission:certifications.manage');
        Route::get('/users/{user}/certifications', [CertificationController::class, 'userCertifications'])->middleware('permission:certifications.view');
        Route::post('/users/{user}/certifications', [CertificationController::class, 'assignToUser'])->middleware('permission:certifications.manage');
        Route::patch('/users/{user}/certifications/{userCertification}', [CertificationController::class, 'updateUserCertification'])->middleware('permission:certifications.manage');
        Route::delete('/users/{user}/certifications/{userCertification}', [CertificationController::class, 'deleteUserCertification'])->middleware('permission:certifications.manage');

        Route::post('/devices/fcm-token', [DeviceController::class, 'register'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware('throttle:operational-telemetry');
        Route::post('/devices/heartbeat', [DeviceController::class, 'heartbeat'])
            ->withoutMiddleware('throttle:authenticated')
            ->middleware('throttle:operational-telemetry');
        Route::delete('/devices/fcm-token/{token}', [DeviceController::class, 'revoke']);
        Route::get('/devices', [DeviceController::class, 'index']);

        Route::get('/admin/roles', [AdminController::class, 'roles'])->middleware('permission:roles.manage');
        Route::get('/admin/wallboard-media/folders', [AdminWallboardMediaFolderController::class, 'index'])
            ->middleware('permission:wallboards.manage');
        Route::post('/admin/wallboard-media/folders', [AdminWallboardMediaFolderController::class, 'store'])
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-admin-write']);
        Route::patch('/admin/wallboard-media/folders/{folder}', [AdminWallboardMediaFolderController::class, 'update'])
            ->whereUlid('folder')
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-admin-write']);
        Route::delete('/admin/wallboard-media/folders/{folder}', [AdminWallboardMediaFolderController::class, 'destroy'])
            ->whereUlid('folder')
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-admin-write']);
        Route::get('/admin/wallboard-media/assets', [AdminWallboardMediaAssetController::class, 'index'])
            ->middleware('permission:wallboards.manage');
        Route::post('/admin/wallboard-media/assets', [AdminWallboardMediaAssetController::class, 'store'])
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-media-upload']);
        Route::get('/admin/wallboard-media/assets/{asset}', [AdminWallboardMediaAssetController::class, 'show'])
            ->whereUlid('asset')
            ->middleware('permission:wallboards.manage');
        Route::patch('/admin/wallboard-media/assets/{asset}', [AdminWallboardMediaAssetController::class, 'update'])
            ->whereUlid('asset')
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-admin-write']);
        Route::delete('/admin/wallboard-media/assets/{asset}', [AdminWallboardMediaAssetController::class, 'destroy'])
            ->whereUlid('asset')
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-admin-write']);
        Route::get('/admin/wallboard-media/assets/{asset}/content', [AdminWallboardMediaAssetController::class, 'content'])
            ->whereUlid('asset')
            ->middleware('permission:wallboards.manage')
            ->name('wallboard-media.admin-content');
        Route::get('/admin/wallboard-media/assets/{asset}/thumbnail', [AdminWallboardMediaAssetController::class, 'thumbnail'])
            ->whereUlid('asset')
            ->middleware('permission:wallboards.manage')
            ->name('wallboard-media.admin-thumbnail');
        Route::get('/admin/wallboard-media/playlists', [AdminWallboardMediaPlaylistController::class, 'index'])
            ->middleware('permission:wallboards.manage');
        Route::post('/admin/wallboard-media/playlists', [AdminWallboardMediaPlaylistController::class, 'store'])
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-admin-write']);
        Route::get('/admin/wallboard-media/playlists/{mediaPlaylist}', [AdminWallboardMediaPlaylistController::class, 'show'])
            ->whereUlid('mediaPlaylist')
            ->middleware('permission:wallboards.manage');
        Route::patch('/admin/wallboard-media/playlists/{mediaPlaylist}', [AdminWallboardMediaPlaylistController::class, 'update'])
            ->whereUlid('mediaPlaylist')
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-admin-write']);
        Route::delete('/admin/wallboard-media/playlists/{mediaPlaylist}', [AdminWallboardMediaPlaylistController::class, 'destroy'])
            ->whereUlid('mediaPlaylist')
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-admin-write']);
        Route::get('/admin/wallboard-playlists', [AdminWallboardPlaylistController::class, 'index'])
            ->middleware('permission:wallboards.manage');
        Route::post('/admin/wallboard-playlists', [AdminWallboardPlaylistController::class, 'store'])
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-admin-write']);
        Route::get('/admin/wallboard-playlists/{wallboardPlaylist}', [AdminWallboardPlaylistController::class, 'show'])
            ->middleware('permission:wallboards.manage');
        Route::post('/admin/wallboard-playlists/{wallboardPlaylist}/preview-state', [AdminWallboardPlaylistController::class, 'previewState'])
            ->withoutMiddleware('audit.privileged')
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-playlist-preview']);
        Route::get('/admin/wallboard-news-images/{image}', [AdminWallboardNewsImageController::class, 'show'])
            ->where('image', '[a-f0-9]{64}')
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-preview-news-image']);
        Route::patch('/admin/wallboard-playlists/{wallboardPlaylist}', [AdminWallboardPlaylistController::class, 'update'])
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-admin-write']);
        Route::delete('/admin/wallboard-playlists/{wallboardPlaylist}', [AdminWallboardPlaylistController::class, 'destroy'])
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-admin-write']);
        Route::get('/admin/wallboards', [AdminWallboardController::class, 'index'])->middleware('permission:wallboards.manage');
        Route::post('/admin/wallboards', [AdminWallboardController::class, 'store'])->middleware('permission:wallboards.manage');
        Route::get('/admin/wallboards/{wallboard}', [AdminWallboardController::class, 'show'])->middleware('permission:wallboards.manage');
        Route::patch('/admin/wallboards/{wallboard}', [AdminWallboardController::class, 'update'])->middleware('permission:wallboards.manage');
        Route::delete('/admin/wallboards/{wallboard}', [AdminWallboardController::class, 'destroy'])->middleware('permission:wallboards.manage');
        Route::patch('/admin/wallboards/{wallboard}/playlist', [AdminWallboardPlaylistController::class, 'assign'])
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-admin-write']);
        Route::post('/admin/wallboards/{wallboard}/pair', [AdminWallboardController::class, 'pair'])
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-pairing-approve']);
        Route::post('/admin/wallboards/{wallboard}/sessions/revoke', [AdminWallboardController::class, 'revokeSessions'])->middleware('permission:wallboards.manage');
        Route::post('/admin/wallboards/{wallboard}/display', [AdminWallboardController::class, 'setDisplay'])
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-admin-write']);
        Route::post('/admin/wallboards/{wallboard}/refresh', [AdminWallboardController::class, 'refresh'])
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-admin-write']);
        Route::post('/admin/wallboards/{wallboard}/focus-test', [AdminWallboardController::class, 'previewFocus'])
            ->middleware(['permission:wallboards.manage', 'throttle:wallboard-focus-preview']);
        Route::post('/admin/roles', [AdminController::class, 'storeRole'])->middleware('permission:roles.manage');
        Route::patch('/admin/roles/{role}', [AdminController::class, 'updateRole'])->middleware('permission:roles.manage');
        Route::delete('/admin/roles/{role}', [AdminController::class, 'destroyRole'])->middleware('permission:roles.delete');
        Route::get('/admin/permissions', [AdminController::class, 'permissions'])->middleware('permission:roles.manage');
        Route::get('/admin/teams', [AdminController::class, 'teams'])->middleware('permission:teams.manage');
        Route::get('/admin/teams/certification-options', [AdminController::class, 'teamCertificationOptions'])->middleware('permission:teams.manage');
        Route::post('/admin/teams', [AdminController::class, 'storeTeam'])->middleware('permission:teams.manage');
        Route::patch('/admin/teams/{team}', [AdminController::class, 'updateTeam'])->middleware('permission:teams.manage');
        Route::get('/admin/audit-logs', [AdminController::class, 'auditLogs'])->middleware('permission:audit.view');
        Route::get('/admin/audit-users', [AdminController::class, 'auditUsers'])->middleware('permission:audit.view');
        Route::get('/status/audit-users', [AdminController::class, 'auditUsers'])->middleware('permission:status.audit.view');
        Route::get('/admin/settings', [AdminController::class, 'settings'])->middleware('permission:settings.manage');
        Route::patch('/admin/settings', [AdminController::class, 'updateSettings'])->middleware('permission:settings.manage');
        Route::get('/admin/speech', [AdminSpeechController::class, 'show'])
            ->middleware(['permission:settings.manage', 'throttle:speech-admin-read']);
        Route::patch('/admin/speech/settings', [AdminSpeechController::class, 'update'])
            ->middleware(['permission:settings.manage', 'throttle:speech-admin-write']);
        Route::post('/admin/speech/models/{modelId}/install', [AdminSpeechController::class, 'install'])
            ->where('modelId', '[a-z0-9_]{1,80}')
            ->middleware(['permission:settings.manage', 'throttle:speech-admin-install']);
        Route::post('/admin/speech/voice-profiles', [AdminSpeechController::class, 'storeVoiceProfile'])
            ->middleware(['permission:settings.manage', 'throttle:speech-admin-upload']);
        Route::delete('/admin/speech/voice-profiles/{voiceProfile}', [AdminSpeechController::class, 'destroyVoiceProfile'])
            ->whereUlid('voiceProfile')
            ->middleware(['permission:settings.manage', 'throttle:speech-admin-write']);
        Route::post('/admin/speech/previews', [AdminSpeechController::class, 'createPreview'])
            ->middleware(['permission:settings.manage', 'throttle:speech-admin-preview']);
        Route::get('/admin/speech/previews/{preview}', [AdminSpeechController::class, 'preview'])
            ->whereUlid('preview')
            ->middleware(['permission:settings.manage', 'throttle:speech-admin-read']);
        Route::get('/admin/speech/previews/{preview}/audio', [AdminSpeechController::class, 'previewAudio'])
            ->whereUlid('preview')
            ->middleware(['permission:settings.manage', 'throttle:speech-admin-read']);
        Route::post('/admin/speech/cache/regenerate', [AdminSpeechController::class, 'regenerateCache'])
            ->middleware(['permission:settings.manage', 'throttle:speech-admin-write']);
        Route::get('/admin/knmi', [AdminKnmiController::class, 'show'])
            ->middleware(['permission:settings.manage', 'throttle:knmi-admin-read']);
        Route::get('/admin/knmi/catalog', [AdminKnmiController::class, 'catalog'])
            ->middleware(['permission:settings.manage', 'throttle:knmi-admin-read']);
        Route::patch('/admin/knmi', [AdminKnmiController::class, 'update'])
            ->middleware(['permission:settings.manage', 'throttle:knmi-admin-write']);
        Route::post('/admin/knmi/refresh', [AdminKnmiController::class, 'refresh'])
            ->middleware(['permission:settings.manage', 'throttle:knmi-admin-write']);
        Route::post('/admin/knmi/precipitation/refresh', [AdminKnmiController::class, 'refreshPrecipitation'])
            ->middleware(['permission:settings.manage', 'throttle:knmi-admin-write']);
        Route::post('/admin/knmi/datasets/{dataset}/refresh', [AdminKnmiController::class, 'refreshDataset'])
            ->where('dataset', implode('|', [
                'harmonie_arome_cy43_p1',
                'radar_forecast',
                'seamless_precipitation_ensemble_forecast_probabilities',
                'knmi_edr_observations',
                'eumetsat_mtg_li',
                'open_meteo',
                'noaa_swpc_kp',
            ]))
            ->middleware(['permission:settings.manage', 'throttle:knmi-admin-write']);
        Route::get('/admin/store-review/status', [AdminStoreReviewController::class, 'status'])->middleware('permission:settings.manage');
        Route::patch('/admin/store-review/accounts/{platform}', [AdminStoreReviewController::class, 'updateAccount'])->middleware(['permission:settings.manage', 'throttle:api']);
        Route::post('/admin/branding/logo', [BrandingController::class, 'uploadLogo'])->middleware('permission:settings.manage');
        Route::delete('/admin/branding/logo', [BrandingController::class, 'deleteLogo'])->middleware('permission:settings.manage');
        Route::post('/admin/settings/mail/test', [AdminController::class, 'testMail'])->middleware('permission:settings.manage');
        Route::get('/admin/developer-access', [AdminDeveloperController::class, 'developerAccess'])->middleware('permission:system.developer-access.manage');
        Route::post('/admin/developer-access/key', [AdminDeveloperController::class, 'generateDeveloperKey'])->middleware('permission:system.developer-access.manage');
        Route::delete('/admin/developer-access/key', [AdminDeveloperController::class, 'disableDeveloperKey'])->middleware('permission:system.developer-access.manage');
        Route::get('/admin/system/version', [AdminDeveloperController::class, 'version'])->middleware('permission:system.health.view');
        Route::get('/admin/system/metrics', [HealthController::class, 'metrics'])
            ->middleware(['permission:system.health.view', 'throttle:system-metrics']);
        Route::post('/admin/system/update', [AdminDeveloperController::class, 'runUpdate'])->middleware('permission:system.update.execute');
        Route::post('/admin/system/reboot', [AdminDeveloperController::class, 'reboot'])->middleware('permission:system.reboot.execute');
        Route::get('/admin/routing/osrm', [AdminOsrmController::class, 'show'])
            ->middleware(['permission:system.health.view,system.routing.manage', 'throttle:osrm-admin-read']);
        Route::post('/admin/routing/osrm/operations', [AdminOsrmController::class, 'store'])
            ->middleware(['permission:system.routing.manage', 'throttle:osrm-admin-write']);
        Route::get('/admin/routing/osrm/operations/{operation}', [AdminOsrmController::class, 'operation'])
            ->middleware(['permission:system.health.view,system.routing.manage', 'throttle:osrm-admin-read']);
        Route::get('/admin/backups', [BackupController::class, 'index'])->middleware('permission:backups.manage');
        Route::patch('/admin/backups/settings', [BackupController::class, 'updateSettings'])->middleware('permission:backups.manage');
        Route::post('/admin/backups/samba-shares', [BackupController::class, 'sambaShares'])->middleware('permission:backups.manage');
        Route::post('/admin/backups', [BackupController::class, 'create'])->middleware('permission:backups.manage');
        Route::post('/admin/backups/upload-restore', [BackupController::class, 'uploadRestore'])->middleware('permission:backups.manage');
        Route::get('/admin/backups/operations/{requestId}', [BackupController::class, 'operationStatus'])->middleware('permission:backups.manage');
        Route::post('/admin/backups/{backup}/verify', [BackupController::class, 'verify'])->middleware('permission:backups.manage');
        Route::post('/admin/backups/{backup}/restore', [BackupController::class, 'restore'])->middleware('permission:backups.manage');
        Route::get('/admin/push/logs', [AdminController::class, 'pushLogs'])->middleware('permission:settings.push.manual.send');
        Route::get('/admin/push/options', [AdminPushController::class, 'options'])->middleware('permission:settings.push.manual.send');
        Route::get('/admin/push/tokens', [AdminPushController::class, 'tokens'])->middleware('permission:settings.push.tokens.manage');
        Route::post('/admin/push/tokens/{token}/revoke', [AdminPushController::class, 'revoke'])->middleware('permission:settings.push.tokens.manage');
        Route::post('/admin/push/tokens/{token}/activate', [AdminPushController::class, 'activate'])->middleware('permission:settings.push.tokens.manage');
        Route::post('/admin/push/manual', [AdminPushController::class, 'send'])->middleware('permission:settings.push.manual.send');

        Route::get('/admin/updates/android', [UpdateController::class, 'index'])->middleware('permission:updates.manage');
        Route::post('/admin/updates/android', [UpdateController::class, 'store'])->middleware('permission:updates.manage');
        Route::post('/admin/updates/android/upload', [UpdateController::class, 'uploadAndroid'])->middleware('permission:updates.manage');
        Route::patch('/admin/updates/android/{version}', [UpdateController::class, 'update'])->middleware('permission:updates.manage');
        Route::get('/admin/updates/ios', [UpdateController::class, 'indexIos'])->middleware('permission:updates.manage');
        Route::post('/admin/updates/ios', [UpdateController::class, 'storeIos'])->middleware('permission:updates.manage');
        Route::patch('/admin/updates/ios/{version}', [UpdateController::class, 'update'])->middleware('permission:updates.manage');
        Route::get('/admin/pilot-report/form-config', [PilotIncidentReportController::class, 'formConfig'])->middleware('permission:settings.manage');
        Route::patch('/admin/pilot-report/form-config', [PilotIncidentReportController::class, 'updateFormConfig'])->middleware('permission:settings.manage');
        Route::get('/admin/incident-form/config', [IncidentFormController::class, 'show'])->middleware('permission:settings.manage');
        Route::patch('/admin/incident-form/config', [IncidentFormController::class, 'update'])->middleware('permission:settings.manage');
        Route::get('/admin/health', [HealthController::class, 'admin'])->middleware('permission:system.health.view');
        Route::get('/admin/queues', [HealthController::class, 'queues'])->middleware('permission:system.health.view');
        Route::get('/admin/websocket-status', [HealthController::class, 'websocket'])->middleware('permission:system.health.view');
    });
});

Route::fallback(fn () => ApiResponse::error('api_route_not_found', 'DIS API route was not found.', 404));
