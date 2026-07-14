<?php

use App\Console\Commands\ApplyVacationStatuses;
use App\Console\Commands\FinishSystemUpdateCommand;
use App\Console\Commands\PruneOperationalData;
use App\Console\Commands\RunSystemUpdateCommand;
use App\Console\Commands\SendDevicePresencePing;
use App\Console\Commands\SendScheduledTestAlert;
use App\Console\Commands\SystemSelfCheck;
use App\Http\Middleware\AuditPrivilegedRequest;
use App\Http\Middleware\AuditRateLimitedRequest;
use App\Http\Middleware\AuthenticateTwoFactorChallenge;
use App\Http\Middleware\EnsureFirstPartyRequestsAreStateful;
use App\Http\Middleware\EnsureOperationalAccess;
use App\Http\Middleware\EnsureTwoFactorComplete;
use App\Http\Middleware\EnsureWebSessionIsValid;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RestrictStoreReviewAccess;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        channels: __DIR__.'/../routes/channels.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withCommands([
        FinishSystemUpdateCommand::class,
        ApplyVacationStatuses::class,
        PruneOperationalData::class,
        RunSystemUpdateCommand::class,
        SendDevicePresencePing::class,
        SendScheduledTestAlert::class,
        SystemSelfCheck::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            headers: SymfonyRequest::HEADER_X_FORWARDED_FOR
                | SymfonyRequest::HEADER_X_FORWARDED_HOST
                | SymfonyRequest::HEADER_X_FORWARDED_PORT
                | SymfonyRequest::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->prependToGroup('api', EnsureFirstPartyRequestsAreStateful::class);
        $middleware->preventRequestsDuringMaintenance([
            'api/developer/system/maintenance',
        ]);
        $middleware->append([
            RequestId::class,
            SecurityHeaders::class,
            AuditRateLimitedRequest::class,
        ]);
        $middleware->alias([
            'audit.privileged' => AuditPrivilegedRequest::class,
            'operational' => EnsureOperationalAccess::class,
            'store.review' => RestrictStoreReviewAccess::class,
            'two_factor.challenge' => AuthenticateTwoFactorChallenge::class,
            'two_factor.complete' => EnsureTwoFactorComplete::class,
            'web.session' => EnsureWebSessionIsValid::class,
            'permission' => RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if ($response->getStatusCode() === Response::HTTP_TOO_MANY_REQUESTS) {
                app(AuditRateLimitedRequest::class)->audit($request, $response);
            }

            return app(SecurityHeaders::class)->apply($request, $response);
        });
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
        $exceptions->render(function (Throwable $exception, $request) {
            if ($exception instanceof HttpResponseException) {
                return $exception->getResponse();
            }

            if (! $request->is('api/*')) {
                return null;
            }

            if ($exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();
                $code = match ($status) {
                    401 => 'unauthenticated',
                    403 => 'forbidden',
                    404 => 'not_found',
                    409 => 'conflict',
                    429 => 'rate_limited',
                    default => $status >= 500 ? 'server_error' : 'request_failed',
                };
                $message = match ($status) {
                    400 => 'The request is invalid.',
                    401 => 'Authentication is required.',
                    403 => 'You do not have permission to perform this action.',
                    404 => 'The requested resource was not found.',
                    405 => 'The request method is not allowed.',
                    409 => 'The request conflicts with current state.',
                    413 => 'The request is too large.',
                    415 => 'The request content type is not supported.',
                    419 => 'The CSRF token is invalid or expired.',
                    429 => 'Too many requests.',
                    default => $status >= 500 ? 'Server error.' : 'Request failed.',
                };
                $details = [];
                if ($request->attributes->has('request_id')) {
                    $details['request_id'] = $request->attributes->get('request_id');
                }

                $response = ApiResponse::error($code, $message, $status, $details);
                foreach ($exception->getHeaders() as $name => $value) {
                    $response->headers->set($name, $value);
                }

                return $response;
            }

            $details = [];
            if ($request->attributes->has('request_id')) {
                $details['request_id'] = $request->attributes->get('request_id');
            }

            return ApiResponse::error('server_error', 'Server error.', 500, $details);
        });
    })
    ->create();
