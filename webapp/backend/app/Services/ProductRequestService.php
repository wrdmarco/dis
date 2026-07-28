<?php

namespace App\Services;

use App\Exceptions\ProductRequestConflictException;
use App\Models\PersonalAccessToken;
use App\Models\ProductRequest;
use App\Models\User;
use App\Repositories\ProductRequestRepository;
use App\Support\ApiDateTime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductRequestService
{
    public function __construct(
        private readonly ProductRequestRepository $requests,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  list<string>  $statuses
     * @param  list<string>  $types
     */
    public function search(
        array $statuses,
        array $types,
        bool $onlyMine,
        ?string $search,
        int $perPage,
        User $actor,
    ): LengthAwarePaginator {
        $this->requireWebPermissions($actor, 'product-requests.view');

        return $this->requests->search(
            $statuses,
            $types,
            $onlyMine ? $actor->id : null,
            $search,
            $perPage,
        );
    }

    public function show(ProductRequest $productRequest, User $actor): ProductRequest
    {
        $this->requireWebPermissions($actor, 'product-requests.view');

        return $this->requests->forPresentation($productRequest->id, withHistory: true);
    }

    /**
     * @param  array{type: string, title: string, description: string}  $data
     */
    public function create(array $data, User $actor): ProductRequest
    {
        $this->requireWebPermissions(
            $actor,
            'product-requests.view',
            'product-requests.create',
        );

        return DB::transaction(function () use ($data, $actor): ProductRequest {
            $productRequest = $this->requests->createRequest([
                'requester_id' => $actor->id,
                'requester_name_snapshot' => $actor->name,
                'type' => $data['type'],
                'title' => $data['title'],
                'description' => $data['description'],
                'status' => 'open',
                'updated_by' => $actor->id,
                'lock_version' => 1,
            ]);

            $this->requests->createStatusHistory([
                'product_request_id' => $productRequest->id,
                'from_status' => null,
                'to_status' => 'open',
                'note' => null,
                'changed_by' => $actor->id,
                'changed_by_name_snapshot' => $actor->name,
            ]);

            $this->audit->record(
                action: 'product_requests.created',
                target: $productRequest,
                actor: $actor,
                metadata: [
                    'requester_id' => $actor->id,
                    'type' => $productRequest->type,
                    'status' => $productRequest->status,
                ],
            );

            return $this->requests->forPresentation($productRequest->id, withHistory: true);
        });
    }

    /**
     * @param  array{lock_version: int, type?: string, title?: string, description?: string}  $data
     */
    public function updateContent(
        ProductRequest $productRequest,
        array $data,
        User $actor,
    ): ProductRequest {
        $this->requireWebPermissions($actor, 'product-requests.view');

        return DB::transaction(function () use ($productRequest, $data, $actor): ProductRequest {
            $locked = $this->requests->lock($productRequest->id);
            $this->assertCanUpdateContent($locked, $actor);

            $this->assertCurrentVersion($locked, $data['lock_version']);

            if ($locked->isTerminal()) {
                throw new ProductRequestConflictException(
                    'product_request_terminal',
                    'Een opgelost of afgewezen verzoek kan niet meer worden aangepast.',
                    $this->currentState($locked),
                );
            }

            $changes = [];
            foreach (['type', 'title', 'description'] as $field) {
                if (array_key_exists($field, $data) && $locked->getAttribute($field) !== $data[$field]) {
                    $changes[$field] = $data[$field];
                }
            }

            if ($changes === []) {
                return $this->requests->forPresentation($locked->id, withHistory: true);
            }

            $locked->fill($changes + [
                'updated_by' => $actor->id,
                'lock_version' => $locked->lock_version + 1,
            ]);
            $locked->save();

            $this->audit->record(
                action: 'product_requests.updated',
                target: $locked,
                actor: $actor,
                metadata: [
                    'changed_fields' => array_keys($changes),
                    'status' => $locked->status,
                    'type' => $locked->type,
                ],
            );

            return $this->requests->forPresentation($locked->id, withHistory: true);
        });
    }

    private function assertCanUpdateContent(ProductRequest $productRequest, User $actor): void
    {
        if ($actor->hasPermission('product-requests.update-any')) {
            return;
        }

        if (
            $actor->hasPermission('product-requests.update-own')
            && $productRequest->isOwnedBy($actor)
        ) {
            return;
        }

        throw new AuthorizationException;
    }

    /**
     * @param  array{status: string, resolution_note?: string|null, lock_version: int}  $data
     */
    public function changeStatus(
        ProductRequest $productRequest,
        array $data,
        User $actor,
    ): ProductRequest {
        $this->requireWebPermissions(
            $actor,
            'product-requests.view',
            'product-requests.resolve',
        );

        return DB::transaction(function () use ($productRequest, $data, $actor): ProductRequest {
            $locked = $this->requests->lock($productRequest->id);
            $this->assertCurrentVersion($locked, $data['lock_version']);

            $targetStatus = $data['status'];
            if (! in_array($targetStatus, ProductRequest::STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => ['De gekozen verzoekstatus is ongeldig.'],
                ]);
            }

            $this->assertAllowedTransition($locked, $targetStatus);
            $note = $this->normalizeNote($data['resolution_note'] ?? null);
            $reopening = $locked->isTerminal() && $targetStatus === 'open';

            if (
                (in_array($targetStatus, ProductRequest::TERMINAL_STATUSES, true) || $reopening)
                && $note === null
            ) {
                throw ValidationException::withMessages([
                    'resolution_note' => ['Een toelichting is verplicht voor deze statuswijziging.'],
                ]);
            }

            $fromStatus = $locked->status;
            $resolved = in_array($targetStatus, ProductRequest::TERMINAL_STATUSES, true);
            $locked->fill([
                'status' => $targetStatus,
                'resolution_note' => $resolved ? $note : null,
                'resolved_by' => $resolved ? $actor->id : null,
                'resolved_at' => $resolved ? now() : null,
                'updated_by' => $actor->id,
                'lock_version' => $locked->lock_version + 1,
            ]);
            $locked->save();

            $this->requests->createStatusHistory([
                'product_request_id' => $locked->id,
                'from_status' => $fromStatus,
                'to_status' => $targetStatus,
                'note' => $note,
                'changed_by' => $actor->id,
                'changed_by_name_snapshot' => $actor->name,
            ]);

            $this->audit->record(
                action: $this->auditAction($fromStatus, $targetStatus),
                target: $locked,
                actor: $actor,
                metadata: [
                    'from_status' => $fromStatus,
                    'to_status' => $targetStatus,
                    'type' => $locked->type,
                ],
            );

            return $this->requests->forPresentation($locked->id, withHistory: true);
        });
    }

    private function requireWebPermissions(User $actor, string ...$permissions): void
    {
        if ($actor->currentAccessToken() instanceof PersonalAccessToken) {
            throw new AuthorizationException;
        }

        foreach ($permissions as $permission) {
            if (! $actor->hasPermission($permission)) {
                throw new AuthorizationException;
            }
        }
    }

    private function assertCurrentVersion(ProductRequest $productRequest, int $expectedVersion): void
    {
        if ($productRequest->lock_version === $expectedVersion) {
            return;
        }

        throw new ProductRequestConflictException(
            'product_request_version_conflict',
            'Het verzoek is ondertussen gewijzigd. Laad het verzoek opnieuw.',
            $this->currentState($productRequest),
        );
    }

    private function assertAllowedTransition(ProductRequest $productRequest, string $targetStatus): void
    {
        $allowed = match ($productRequest->status) {
            'open' => ['in_progress', 'resolved', 'rejected'],
            'in_progress' => ['open', 'resolved', 'rejected'],
            'resolved', 'rejected' => ['open'],
            default => [],
        };

        if (in_array($targetStatus, $allowed, true)) {
            return;
        }

        throw new ProductRequestConflictException(
            'product_request_transition_conflict',
            'Deze statuswijziging is niet toegestaan vanuit de huidige status.',
            $this->currentState($productRequest),
        );
    }

    private function normalizeNote(mixed $note): ?string
    {
        if (! is_string($note)) {
            return null;
        }

        $note = trim($note);

        return $note === '' ? null : $note;
    }

    private function auditAction(string $fromStatus, string $toStatus): string
    {
        return match (true) {
            $toStatus === 'resolved' => 'product_requests.resolved',
            $toStatus === 'rejected' => 'product_requests.rejected',
            in_array($fromStatus, ProductRequest::TERMINAL_STATUSES, true) && $toStatus === 'open' => 'product_requests.reopened',
            default => 'product_requests.status_changed',
        };
    }

    /** @return array<string, mixed> */
    private function currentState(ProductRequest $productRequest): array
    {
        return [
            'id' => $productRequest->id,
            'status' => $productRequest->status,
            'lock_version' => $productRequest->lock_version,
            'updated_at' => ApiDateTime::dateTime($productRequest->updated_at),
        ];
    }
}
