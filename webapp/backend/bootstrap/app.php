<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\EnsureOperationalAccess;
use App\Http\Middleware\EnsureTwoFactorComplete;
use App\Http\Middleware\AuditPrivilegedRequest;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        channels: __DIR__.'/../routes/channels.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/health',
    )
    ->withCommands([
        App\Console\Commands\PruneOperationalData::class,
        App\Console\Commands\SystemSelfCheck::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->append([
            RequestId::class,
            SecurityHeaders::class,
        ]);
        $middleware->alias([
            'audit.privileged' => AuditPrivilegedRequest::class,
            'operational' => EnsureOperationalAccess::class,
            'two_factor.complete' => EnsureTwoFactorComplete::class,
            'permission' => RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $exception) {
            return ApiResponse::error('validation_failed', 'The given data was invalid.', 422, $exception->errors());
        });
        $exceptions->render(function (AuthenticationException $exception) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', 401);
        });
        $exceptions->render(function (AuthorizationException $exception) {
            return ApiResponse::error('forbidden', 'You do not have permission to perform this action.', 403);
        });
        $exceptions->render(function (ModelNotFoundException $exception) {
            return ApiResponse::error('not_found', 'The requested resource was not found.', 404);
        });
    })
    ->create();
