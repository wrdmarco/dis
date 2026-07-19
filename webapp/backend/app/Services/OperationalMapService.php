<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\SystemSetting;
use App\Models\User;

final class OperationalMapService
{
    /**
     * @return array{
     *     command_centers: list<array{id: string, name: string, address: string|null, latitude: float, longitude: float}>,
     *     historical_incidents: list<array{id: string, reference: string, title: string, status: string, priority: string, location_label: string|null, latitude: float, longitude: float, closed_at: string|null}>,
     *     pilot_homes: list<array{id: string, name: string, home_city: string|null, latitude: float, longitude: float, teams: list<string>}>
     * }
     */
    public function layers(
        bool $includePilotHomes = false,
        bool $includeCommandCenters = true,
        bool $includeHistoricalIncidents = true,
        bool $includeTestIncidents = true,
    ): array {
        return [
            'command_centers' => $includeCommandCenters ? $this->commandCenters() : [],
            'historical_incidents' => $includeHistoricalIncidents ? $this->historicalIncidents($includeTestIncidents) : [],
            'pilot_homes' => $includePilotHomes ? $this->pilotHomes() : [],
        ];
    }

    /**
     * @return list<array{id: string, name: string, address: string|null, latitude: float, longitude: float}>
     */
    private function commandCenters(): array
    {
        $configuredCenters = SystemSetting::value('operational_map.command_centers', []);
        if (! is_array($configuredCenters)) {
            return [];
        }

        $centers = [];
        foreach ($configuredCenters as $index => $center) {
            if (! is_array($center)) {
                continue;
            }

            $latitude = $this->coordinate($center['latitude'] ?? null);
            $longitude = $this->coordinate($center['longitude'] ?? null);
            $name = trim((string) ($center['name'] ?? $center['label'] ?? ''));

            if ($latitude === null || $longitude === null || $name === '') {
                continue;
            }

            $centers[] = [
                'id' => (string) ($center['id'] ?? 'command-center-'.$index),
                'name' => $name,
                'address' => is_string($center['address'] ?? null) ? $center['address'] : null,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
        }

        return $centers;
    }

    /**
     * @return list<array{id: string, reference: string, title: string, status: string, priority: string, location_label: string|null, latitude: float, longitude: float, closed_at: string|null}>
     */
    private function historicalIncidents(bool $includeTestIncidents): array
    {
        return Incident::query()
            ->whereIn('status', ['resolved', 'cancelled'])
            ->when(! $includeTestIncidents, fn ($query) => $query->where('is_test', false))
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->latest('closed_at')
            ->limit(500)
            ->get(['id', 'reference', 'title', 'status', 'priority', 'location_label', 'latitude', 'longitude', 'closed_at'])
            ->map(fn (Incident $incident): array => [
                'id' => $incident->id,
                'reference' => $incident->reference,
                'title' => $incident->title,
                'status' => $incident->status,
                'priority' => $incident->priority,
                'location_label' => $incident->location_label,
                'latitude' => (float) $incident->latitude,
                'longitude' => (float) $incident->longitude,
                'closed_at' => $incident->closed_at?->toISOString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, name: string, home_city: string|null, latitude: float, longitude: float, teams: list<string>}>
     */
    private function pilotHomes(): array
    {
        return User::query()
            ->where('account_status', 'active')
            ->whereNotNull('home_latitude')
            ->whereNotNull('home_longitude')
            ->whereHas('roles', fn ($roles) => $roles->where('roles.can_use_operator_app', true))
            ->with(['teams:id,name'])
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'home_city', 'home_latitude', 'home_longitude', 'account_status'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'home_city' => $user->home_city,
                'latitude' => (float) $user->home_latitude,
                'longitude' => (float) $user->home_longitude,
                'teams' => $user->teams->pluck('name')->values()->all(),
            ])
            ->values()
            ->all();
    }

    private function coordinate(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        return is_finite($coordinate) ? $coordinate : null;
    }
}
