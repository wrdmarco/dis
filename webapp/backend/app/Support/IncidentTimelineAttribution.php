<?php

namespace App\Support;

final class IncidentTimelineAttribution
{
    /**
     * @return array{
     *     actor: array{id: string, name: string}|null,
     *     actor_name: string,
     *     description: string
     * }
     */
    public static function make(
        ?string $actorId,
        ?string $actorName,
        string $description,
        string $fallbackName = 'Niet vastgelegd',
    ): array {
        $resolvedActorId = is_string($actorId) && $actorId !== '' ? $actorId : null;
        $resolvedActorName = is_string($actorName) && trim($actorName) !== ''
            ? trim($actorName)
            : null;
        $displayName = $resolvedActorName ?? ($resolvedActorId === null ? $fallbackName : 'Onbekende gebruiker');
        $attributedDescription = match (true) {
            $resolvedActorId !== null || $resolvedActorName !== null => $description.' door '.$displayName,
            $fallbackName === 'Systeem' => $description.' door het systeem',
            default => $description.' (uitvoerder niet vastgelegd)',
        };

        return [
            'actor' => $resolvedActorId === null ? null : [
                'id' => $resolvedActorId,
                'name' => $displayName,
            ],
            'actor_name' => $displayName,
            'description' => $attributedDescription,
        ];
    }
}
