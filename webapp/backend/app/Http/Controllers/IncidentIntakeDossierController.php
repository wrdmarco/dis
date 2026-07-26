<?php

namespace App\Http\Controllers;

use App\Exceptions\IncidentIntakeConflictException;
use App\Http\Requests\IncidentIntakes\DecideIntakePriorityRequest;
use App\Http\Requests\IncidentIntakes\IntakeMutationRequest;
use App\Http\Requests\IncidentIntakes\PatchIntakeDossierRequest;
use App\Http\Requests\IncidentIntakes\StoreIntakeDossierRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Incident;
use App\Models\IncidentIntakeDossier;
use App\Services\IncidentIntakeDossierService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IncidentIntakeDossierController extends Controller
{
    public function __construct(private readonly IncidentIntakeDossierService $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:80', 'regex:/^(open|promoted|closed)(,(open|promoted|closed))*$/'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $statuses = collect(explode(',', (string) ($data['status'] ?? 'open,promoted')))
            ->map(fn (string $status): string => trim($status))
            ->unique()
            ->values()
            ->all();
        $paginator = $this->service->search($statuses, (int) ($data['per_page'] ?? 25));

        return ApiResponse::paginated($paginator, fn (IncidentIntakeDossier $dossier): array => $this->service->present($dossier, $request->user()));
    }

    public function store(StoreIntakeDossierRequest $request): JsonResponse
    {
        try {
            return ApiResponse::success($this->service->create($request->validated(), $request->user()), 201);
        } catch (IncidentIntakeConflictException $exception) {
            return $this->conflict($exception);
        }
    }

    public function show(Request $request, IncidentIntakeDossier $intakeDossier): JsonResponse
    {
        return ApiResponse::success($this->service->present($intakeDossier, $request->user()));
    }

    public function update(PatchIntakeDossierRequest $request, IncidentIntakeDossier $intakeDossier): JsonResponse
    {
        try {
            return ApiResponse::success($this->service->patch($intakeDossier, $request->validated(), $request->user()));
        } catch (IncidentIntakeConflictException $exception) {
            return $this->conflict($exception);
        }
    }

    public function decide(DecideIntakePriorityRequest $request, IncidentIntakeDossier $intakeDossier): JsonResponse
    {
        try {
            return ApiResponse::success($this->service->decidePriority($intakeDossier, $request->validated(), $request->user()));
        } catch (IncidentIntakeConflictException $exception) {
            return $this->conflict($exception);
        }
    }

    public function promote(IntakeMutationRequest $request, IncidentIntakeDossier $intakeDossier): JsonResponse
    {
        try {
            $result = $this->service->promote($intakeDossier, $request->validated(), $request->user());
        } catch (IncidentIntakeConflictException $exception) {
            return $this->conflict($exception);
        }

        return ApiResponse::success([
            'dossier' => $result['dossier'],
            'incident' => MobileApiPayload::incident($result['incident'], $request->user()),
        ], 201);
    }

    public function close(IntakeMutationRequest $request, IncidentIntakeDossier $intakeDossier): JsonResponse
    {
        try {
            return ApiResponse::success($this->service->close($intakeDossier, $request->validated(), $request->user()));
        } catch (IncidentIntakeConflictException $exception) {
            return $this->conflict($exception);
        }
    }

    public function showForIncident(Request $request, Incident $incident): JsonResponse
    {
        $dossier = $incident->intakeDossier()->firstOrFail();

        return ApiResponse::success($this->service->present($dossier, $request->user()));
    }

    public function updateForIncident(PatchIntakeDossierRequest $request, Incident $incident): JsonResponse
    {
        $dossier = $incident->intakeDossier()->firstOrFail();
        try {
            return ApiResponse::success($this->service->patch($dossier, $request->validated(), $request->user()));
        } catch (IncidentIntakeConflictException $exception) {
            return $this->conflict($exception);
        }
    }

    private function conflict(IncidentIntakeConflictException $exception): JsonResponse
    {
        return ApiResponse::error($exception->errorCode, $exception->getMessage(), 409, ['current' => $exception->current]);
    }
}
