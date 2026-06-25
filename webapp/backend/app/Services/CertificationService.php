<?php

namespace App\Services;

use App\Models\Certification;
use App\Models\User;
use App\Models\UserCertification;

final class CertificationService
{
    public function __construct(private readonly AuditService $auditService) {}

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, User $actor): Certification
    {
        $certification = Certification::query()->create($data);
        $this->auditService->record('certifications.created', $certification, $actor);

        return $certification;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function assignToUser(User $user, array $data, User $actor): UserCertification
    {
        $certification = UserCertification::query()->create($data + ['user_id' => $user->id, 'verified_by' => $actor->id, 'verified_at' => now()]);
        $this->auditService->record('certifications.assigned', $user, $actor, ['certification_id' => $data['certification_id']]);

        return $certification->load('certification');
    }
}

