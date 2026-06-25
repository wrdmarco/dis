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
    public function update(Certification $certification, array $data, User $actor): Certification
    {
        $before = $certification->only(array_keys($data));
        $certification->update($data);
        $this->auditService->record('certifications.updated', $certification, $actor, [
            'before' => $before,
            'after' => $certification->only(array_keys($data)),
        ]);

        return $certification->refresh();
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

    /**
     * @param array<string, mixed> $data
     */
    public function selfAssignToUser(User $user, array $data): UserCertification
    {
        $certification = UserCertification::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'certification_id' => $data['certification_id'],
                'certificate_number' => $data['certificate_number'] ?? null,
            ],
            $data + [
                'user_id' => $user->id,
                'status' => 'active',
                'verified_by' => null,
                'verified_at' => null,
            ],
        );
        $this->auditService->record('certifications.self_reported', $user, $user, ['certification_id' => $data['certification_id']]);

        return $certification->load('certification');
    }
}
