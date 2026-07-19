<?php

namespace Tests\Feature;

use JsonException;
use Tests\TestCase;

final class ApplicationVersionConsistencyTest extends TestCase
{
    /** @throws JsonException */
    public function test_admin_and_frontend_use_the_same_web_application_version(): void
    {
        $root = dirname(base_path(), 2);
        $version = trim((string) file_get_contents($root.DIRECTORY_SEPARATOR.'VERSION'));
        $package = json_decode(
            (string) file_get_contents($root.DIRECTORY_SEPARATOR.'webapp'.DIRECTORY_SEPARATOR.'frontend'.DIRECTORY_SEPARATOR.'package.json'),
            true,
            8,
            JSON_THROW_ON_ERROR,
        );
        $lock = json_decode(
            (string) file_get_contents($root.DIRECTORY_SEPARATOR.'webapp'.DIRECTORY_SEPARATOR.'frontend'.DIRECTORY_SEPARATOR.'package-lock.json'),
            true,
            8,
            JSON_THROW_ON_ERROR,
        );

        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/D', $version);
        self::assertSame($version, $package['version'] ?? null);
        self::assertSame($version, $lock['version'] ?? null);
        self::assertSame($version, $lock['packages']['']['version'] ?? null);
    }
}
