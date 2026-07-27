<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DeploymentReferenceSequenceRepository
{
    private const SCOPE = 'global';

    public function referenceExists(string $reference): bool
    {
        return DB::table('deployments')
            ->where('reference', $reference)
            ->exists();
    }

    public function next(): int
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Een inzetvolgnummer moet binnen een databasetransactie worden gereserveerd.');
        }

        $counter = DB::table('deployment_reference_sequence_counters')
            ->where('scope', self::SCOPE)
            ->lockForUpdate()
            ->first();
        if ($counter === null || ! is_numeric($counter->last_sequence)) {
            throw new RuntimeException('De globale inzetreferentieteller is niet beschikbaar.');
        }

        $current = (int) $counter->last_sequence;
        if ($current < 0 || $current === PHP_INT_MAX) {
            throw new RuntimeException('De globale inzetreferentieteller bevat geen geldige vervolgwaarde.');
        }

        $next = $current + 1;
        DB::table('deployment_reference_sequence_counters')
            ->where('scope', self::SCOPE)
            ->update([
                'last_sequence' => $next,
                'updated_at' => now(),
            ]);

        return $next;
    }
}
