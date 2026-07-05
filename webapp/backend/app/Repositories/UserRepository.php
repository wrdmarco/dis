<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class UserRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return User::class;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function search(array $filters, int $perPage): LengthAwarePaginator
    {
        return User::query()
            ->with([
                'roles',
                'teams',
                'fcmTokens' => fn ($tokens) => $tokens
                    ->where('client_type', 'operator')
                    ->where('is_active', true)
                    ->latest('last_seen_at'),
            ])
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%")
                        ->orWhere('home_city', 'ilike', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('account_status', $status))
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->whereHas('roles', fn ($roles) => $roles->where('name', $role)))
            ->when($filters['team'] ?? null, fn ($query, string $team) => $query->whereHas('teams', fn ($teams) => $teams->where('code', $team)))
            ->orderBy('name')
            ->paginate(min(max($perPage, 1), 100));
    }
}
