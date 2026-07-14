<?php

namespace App\Http\Controllers;

use App\Http\Requests\Certifications\StoreCertificationRequest;
use App\Http\Requests\Certifications\UpdateCertificationRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Certification;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\CertificationService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CertificationController extends Controller
{
    public function __construct(private readonly CertificationService $service) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->user()?->hasPermission('certifications.view') !== true) {
            return $this->options();
        }

        if (! $request->has('per_page')) {
            return ApiResponse::success(
                Certification::query()
                    ->with(['userCertifications' => fn ($query) => $query->where('status', 'active')->with('user')->orderBy('expires_at')])
                    ->orderBy('name')
                    ->limit(100)
                    ->get()
                    ->map(fn (Certification $certification): array => MobileApiPayload::certification($certification))
                    ->values(),
            );
        }

        return ApiResponse::paginated(
            Certification::query()
                ->with(['userCertifications' => fn ($query) => $query->where('status', 'active')->with('user')->orderBy('expires_at')])
                ->orderBy('name')
                ->paginate((int) $request->integer('per_page', 25)),
            fn (Certification $certification): array => MobileApiPayload::certification($certification),
        );
    }

    public function options(): JsonResponse
    {
        return ApiResponse::success(
            Certification::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Certification $certification): array => MobileApiPayload::certificationSummary($certification))
                ->values(),
        );
    }

    public function store(StoreCertificationRequest $request): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::certification($this->service->create($request->validated(), $request->user())), 201);
    }

    public function update(UpdateCertificationRequest $request, Certification $certification): JsonResponse
    {
        return ApiResponse::success(MobileApiPayload::certification($this->service->update($certification, $request->validated(), $request->user())));
    }

    public function userCertifications(User $user): JsonResponse
    {
        return ApiResponse::success(
            $user->certifications()
                ->with('certification')
                ->get()
                ->map(fn (UserCertification $certification): array => MobileApiPayload::userCertification($certification))
                ->values(),
        );
    }

    public function myCertifications(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $request->user()
                ?->certifications()
                ->with('certification')
                ->orderBy('expires_at')
                ->get()
                ->map(fn (UserCertification $certification): array => MobileApiPayload::userCertification($certification))
                ->values() ?? [],
        );
    }

    public function storeMyCertification(Request $request): JsonResponse
    {
        $data = $request->validate([
            'certification_id' => ['required', 'ulid', 'exists:certifications,id'],
            'issued_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after:issued_at'],
            'certificate_number' => ['nullable', 'string', 'max:160'],
        ]);

        return ApiResponse::success(MobileApiPayload::userCertification($this->service->selfAssignToUser($request->user(), $data)), 201);
    }

    public function updateMyCertification(Request $request, UserCertification $userCertification): JsonResponse
    {
        abort_unless($userCertification->user_id === $request->user()?->id, 404);
        $userCertification->update($request->validate([
            'issued_at' => ['sometimes', 'date'],
            'expires_at' => ['nullable', 'date'],
            'certificate_number' => ['nullable', 'string', 'max:160'],
            'status' => ['sometimes', 'in:active,expired,revoked'],
        ]));

        return ApiResponse::success(MobileApiPayload::userCertification($userCertification->refresh()->load('certification')));
    }

    public function deleteMyCertification(Request $request, UserCertification $userCertification): Response
    {
        abort_unless($userCertification->user_id === $request->user()?->id, 404);
        $this->service->deleteUserCertification($userCertification, $request->user());

        return response()->noContent();
    }

    public function assignToUser(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'certification_id' => ['required', 'ulid', 'exists:certifications,id'],
            'issued_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after:issued_at'],
            'certificate_number' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'in:active,expired,revoked'],
        ]);

        return ApiResponse::success(MobileApiPayload::userCertification($this->service->assignToUser($user, $data, $request->user())), 201);
    }

    public function updateUserCertification(Request $request, User $user, UserCertification $userCertification): JsonResponse
    {
        abort_unless($userCertification->user_id === $user->id, 404);
        $userCertification->update($request->validate([
            'issued_at' => ['sometimes', 'date'],
            'expires_at' => ['nullable', 'date'],
            'certificate_number' => ['nullable', 'string', 'max:160'],
            'status' => ['sometimes', 'in:active,expired,revoked'],
        ]));

        return ApiResponse::success(MobileApiPayload::userCertification($userCertification->refresh()->load('certification')));
    }

    public function deleteUserCertification(Request $request, User $user, UserCertification $userCertification): Response
    {
        abort_unless($userCertification->user_id === $user->id, 404);
        $this->service->deleteUserCertification($userCertification, $request->user());

        return response()->noContent();
    }
}
