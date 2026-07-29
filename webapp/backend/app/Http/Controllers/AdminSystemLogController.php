<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\ReadSystemLogRequest;
use App\Http\Responses\ApiResponse;
use App\Services\AuditService;
use App\Services\SystemLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminSystemLogController extends Controller
{
    public function __construct(
        private readonly SystemLogService $logs,
        private readonly AuditService $auditService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $logs = $this->logs->files();
        $this->auditService->record(
            'system.logs_listed',
            'system-log',
            $request->user(),
            ['log_count' => count($logs)],
            request: $request,
        );

        return ApiResponse::success(['logs' => $logs])
            ->header('Cache-Control', 'no-store, private');
    }

    public function show(ReadSystemLogRequest $request, string $filename): JsonResponse
    {
        $parameters = $request->parameters();
        $log = $this->logs->read(
            $filename,
            $parameters['lines'],
            $parameters['cursor'],
            $parameters['generation'],
            $parameters['checkpoint'],
            (string) $request->user()->getAuthIdentifier(),
        );
        if ($log === null) {
            return ApiResponse::error(
                'system_log_not_found',
                'Logbestand niet gevonden.',
                404,
            )->header('Cache-Control', 'no-store, private');
        }

        if ($parameters['cursor'] === null || $log['reset']) {
            $this->auditService->record(
                'system.log_view_started',
                'system-log',
                $request->user(),
                [
                    'filename' => $log['name'],
                    'requested_lines' => $parameters['lines'],
                ],
                request: $request,
            );
        }

        return ApiResponse::success($log)
            ->header('Cache-Control', 'no-store, private');
    }

    public function latest(ReadSystemLogRequest $request): JsonResponse
    {
        $filename = $this->logs->latestSource();
        if ($filename === null) {
            return ApiResponse::error(
                'system_log_not_found',
                'Logbestand niet gevonden.',
                404,
            )->header('Cache-Control', 'no-store, private');
        }

        return $this->show($request, $filename);
    }
}
