<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Str;

final class PilotIncidentReportDroneSnapshotService
{
    /**
     * @param  array<string, mixed>  $customFields
     * @param  array<string, mixed>  $existingSnapshots
     * @param  list<string>  $fieldKeys
     * @return array<string, array{asset_id: string, manufacturer: string, model: string}>
     */
    public function capture(array $customFields, array $existingSnapshots, array $fieldKeys): array
    {
        $snapshots = $this->preservedSnapshots($customFields, $existingSnapshots);
        $selected = [];
        $assetIds = [];
        foreach (array_values(array_unique($fieldKeys)) as $fieldKey) {
            $value = $customFields[$fieldKey] ?? null;
            if (! is_scalar($value) || trim((string) $value) === '') {
                unset($snapshots[$fieldKey]);

                continue;
            }

            $assetId = trim((string) $value);
            if (($snapshots[$fieldKey]['asset_id'] ?? null) === $assetId) {
                continue;
            }

            unset($snapshots[$fieldKey]);
            $assetIds[$assetId] = true;
            $selected[$fieldKey] = $assetId;
        }

        $assets = $assetIds === []
            ? collect()
            : Asset::query()
                ->withTrashed()
                ->with(['droneType' => static fn ($types) => $types->withTrashed()])
                ->whereKey(array_keys($assetIds))
                ->get()
                ->keyBy(static fn (Asset $asset): string => (string) $asset->id);

        foreach ($selected as $fieldKey => $selection) {
            $asset = $assets->get($selection);
            $manufacturer = trim((string) $asset?->droneType?->manufacturer);
            $model = trim((string) $asset?->droneType?->model);
            if (! $asset instanceof Asset || $asset->type !== 'drone' || $manufacturer === '' || $model === '') {
                continue;
            }
            $snapshots[$fieldKey] = [
                'asset_id' => $selection,
                'manufacturer' => $manufacturer,
                'model' => $model,
            ];
        }

        return $snapshots;
    }

    /**
     * Retain a removed or retyped field's server-generated snapshot without
     * interpreting any new value from a field that is no longer a user-drone
     * selector. A removed field's validated asset id is kept in custom_fields
     * so the immutable snapshot remains auditable on later report edits.
     *
     * @param  array<string, mixed>  $customFields
     * @param  array<string, mixed>  $previousCustomFields
     * @param  array<string, mixed>  $existingSnapshots
     * @param  list<string>  $currentFieldKeys
     * @return array<string, mixed>
     */
    public function preserveHistoricalSelections(
        array $customFields,
        array $previousCustomFields,
        array $existingSnapshots,
        array $currentFieldKeys,
    ): array {
        $currentKeys = array_fill_keys($currentFieldKeys, true);
        foreach ($existingSnapshots as $fieldKey => $snapshot) {
            if (! is_string($fieldKey) || isset($currentKeys[$fieldKey])) {
                continue;
            }

            $normalized = $this->normalizeSnapshot($snapshot);
            if ($normalized === null
                || ! $this->selectionMatches($previousCustomFields[$fieldKey] ?? null, $normalized['asset_id'])) {
                continue;
            }

            if (array_key_exists($fieldKey, $customFields)) {
                continue;
            }

            $customFields[$fieldKey] = $normalized['asset_id'];
        }

        return $customFields;
    }

    /**
     * @param  array<string, mixed>  $customFields
     * @param  array<string, mixed>  $existingSnapshots
     * @return array<string, array{asset_id: string, manufacturer: string, model: string}>
     */
    private function preservedSnapshots(array $customFields, array $existingSnapshots): array
    {
        $snapshots = [];
        foreach ($existingSnapshots as $fieldKey => $snapshot) {
            if (! is_string($fieldKey)) {
                continue;
            }

            $normalized = $this->normalizeSnapshot($snapshot);
            if ($normalized === null
                || ! $this->selectionMatches($customFields[$fieldKey] ?? null, $normalized['asset_id'])) {
                continue;
            }

            $snapshots[$fieldKey] = $normalized;
        }

        return $snapshots;
    }

    /** @return array{asset_id: string, manufacturer: string, model: string}|null */
    private function normalizeSnapshot(mixed $snapshot): ?array
    {
        if (! is_array($snapshot)
            || ! is_string($snapshot['asset_id'] ?? null)
            || ! is_string($snapshot['manufacturer'] ?? null)
            || ! is_string($snapshot['model'] ?? null)) {
            return null;
        }

        $assetId = trim($snapshot['asset_id']);
        $manufacturer = trim($snapshot['manufacturer']);
        $model = trim($snapshot['model']);
        if (! Str::isUlid($assetId) || $manufacturer === '' || $model === '') {
            return null;
        }

        return [
            'asset_id' => $assetId,
            'manufacturer' => $manufacturer,
            'model' => $model,
        ];
    }

    private function selectionMatches(mixed $selection, string $assetId): bool
    {
        return is_scalar($selection) && trim((string) $selection) === $assetId;
    }
}
