<?php

namespace Tests\Feature;

use App\Support\WallboardConfiguration;
use Tests\TestCase;

final class WallboardPhotoPlaylistIdentifierTest extends TestCase
{
    public function test_uppercase_ulid_is_normalized_to_the_lowercase_database_identifier(): void
    {
        $configuration = WallboardConfiguration::normalize([
            'pages' => [[
                'id' => 'photos',
                'name' => 'Foto’s',
                'type' => 'photo_carousel',
                'duration_seconds' => 30,
                'options' => [
                    'media_playlist_id' => '01KXT8SBRPMQM7X2ARSMFFEMFF',
                    'item_duration_seconds' => 12,
                ],
            ]],
        ]);

        $this->assertSame(
            '01kxt8sbrpmqm7x2arsmffemff',
            $configuration['pages'][0]['options']['media_playlist_id'],
        );
    }
}
