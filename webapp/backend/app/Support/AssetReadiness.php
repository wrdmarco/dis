<?php

namespace App\Support;

use App\Models\Asset;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

final class AssetReadiness
{
    /** @var list<string> */
    private const USABLE_STATUSES = ['ready', 'assigned'];

    /**
     * @return array{effective_status: string, is_effectively_ready: bool, maintenance_overdue: bool}
     */
    public static function fields(Asset $asset, ?DateTimeInterface $operationalDate = null): array
    {
        $maintenanceOverdue = self::maintenanceOverdue($asset, $operationalDate);
        $isEffectivelyReady = in_array((string) $asset->status, self::USABLE_STATUSES, true)
            && ! $maintenanceOverdue;

        return [
            'effective_status' => $maintenanceOverdue
                && in_array((string) $asset->status, self::USABLE_STATUSES, true)
                    ? 'maintenance'
                    : (string) $asset->status,
            'is_effectively_ready' => $isEffectivelyReady,
            'maintenance_overdue' => $maintenanceOverdue,
        ];
    }

    public static function isEffectivelyReady(Asset $asset, ?DateTimeInterface $operationalDate = null): bool
    {
        return self::fields($asset, $operationalDate)['is_effectively_ready'];
    }

    public static function maintenanceOverdue(Asset $asset, ?DateTimeInterface $operationalDate = null): bool
    {
        if ($asset->maintenance_due_at === null) {
            return false;
        }

        return $asset->maintenance_due_at->toDateString() < self::operationalDate($operationalDate)->toDateString();
    }

    /**
     * Apply the same effective-readiness rule in database eligibility queries.
     *
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public static function constrainEffectivelyReady(
        Builder $query,
        ?DateTimeInterface $operationalDate = null,
        string $table = 'assets',
    ): Builder {
        $date = self::operationalDate($operationalDate)->toDateString();

        return $query
            ->whereIn("{$table}.status", self::USABLE_STATUSES)
            ->where(function (Builder $maintenance) use ($date, $table): void {
                $maintenance
                    ->whereNull("{$table}.maintenance_due_at")
                    ->orWhereDate("{$table}.maintenance_due_at", '>=', $date);
            });
    }

    private static function operationalDate(?DateTimeInterface $date): CarbonImmutable
    {
        $timezone = (string) config('app.timezone', 'Europe/Amsterdam');

        return $date === null
            ? CarbonImmutable::now($timezone)->startOfDay()
            : CarbonImmutable::instance($date)->setTimezone($timezone)->startOfDay();
    }
}
