<?php

namespace Tests\Unit;

use App\Services\WallboardMaintenanceNoticeService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class WallboardMaintenanceNoticeServiceTest extends TestCase
{
    private string $directory;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dis-wallboard-maintenance-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $this->path = $this->directory.DIRECTORY_SEPARATOR.'wallboard-status.json';
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19T10:00:00Z'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        if (is_file($this->path) || is_link($this->path)) {
            unlink($this->path);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_it_returns_the_exact_public_contract_for_a_valid_notice(): void
    {
        $this->writeNotice('update', '2026-07-19T10:00:00Z', '2026-07-19T16:00:00Z');

        self::assertSame([
            'active' => true,
            'kind' => 'update',
            'title' => 'Systeem wordt bijgewerkt',
            'message' => 'Dit wallboard blijft op de hoogte en herstelt automatisch zodra de update veilig is afgerond.',
            'started_at' => '2026-07-19T10:00:00Z',
            'estimated_duration_seconds' => null,
            'estimated_completion_at' => null,
            'remaining_seconds' => null,
            'expires_at' => '2026-07-19T16:00:00Z',
        ], $this->service()->current());

        $this->writeNotice('maintenance', '2026-07-19T10:00:00Z', '2026-07-19T10:30:00Z');

        self::assertSame('D.I.S. is tijdelijk in onderhoud', $this->service()->current()['title'] ?? null);
    }

    public function test_it_returns_a_live_countdown_baseline_for_a_version_two_update_notice(): void
    {
        $this->writeEstimatedNotice(
            '2026-07-19T09:55:00Z',
            900,
            '2026-07-19T10:10:00Z',
            '2026-07-19T15:55:00Z',
        );

        self::assertSame([
            'active' => true,
            'kind' => 'update',
            'title' => 'Systeem wordt bijgewerkt',
            'message' => 'Dit wallboard blijft op de hoogte en herstelt automatisch zodra de update veilig is afgerond.',
            'started_at' => '2026-07-19T09:55:00Z',
            'estimated_duration_seconds' => 900,
            'estimated_completion_at' => '2026-07-19T10:10:00Z',
            'remaining_seconds' => 600,
            'expires_at' => '2026-07-19T15:55:00Z',
        ], $this->service()->current());

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19T10:20:00Z'));
        $overdue = $this->service()->current();
        self::assertNotNull($overdue, 'Een verlopen prognose mag de onderhoudsnotice niet beëindigen.');
        self::assertSame(0, $overdue['remaining_seconds']);
    }

    public function test_it_rejects_inconsistent_or_unbounded_version_two_estimates(): void
    {
        $this->writeEstimatedNotice(
            '2026-07-19T10:00:00Z',
            900,
            '2026-07-19T10:14:59Z',
            '2026-07-19T16:00:00Z',
        );
        self::assertNull($this->service()->current());

        $this->writeEstimatedNotice(
            '2026-07-19T10:00:00Z',
            2701,
            '2026-07-19T10:45:01Z',
            '2026-07-19T16:00:00Z',
        );
        self::assertNull($this->service()->current());
    }

    public function test_it_fails_closed_for_expired_future_or_overlong_notices(): void
    {
        $this->writeNotice('update', '2026-07-19T09:00:00Z', '2026-07-19T10:00:00Z');
        self::assertNull($this->service()->current());

        $this->writeNotice('update', '2026-07-19T10:01:01Z', '2026-07-19T11:00:00Z');
        self::assertNull($this->service()->current());

        $this->writeNotice('update', '2026-07-19T10:00:00Z', '2026-07-19T16:00:01Z');
        self::assertNull($this->service()->current());
    }

    public function test_it_rejects_malformed_or_extended_envelopes_and_unsafe_files(): void
    {
        file_put_contents($this->path, '{not-json');
        self::assertNull($this->service()->current());

        $payload = $this->payload('update', '2026-07-19T10:00:00Z', '2026-07-19T11:00:00Z');
        $payload['message'] = 'Door de writer aangeleverde tekst mag niet worden vertrouwd.';
        file_put_contents($this->path, json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertNull($this->service()->current());

        file_put_contents($this->path, str_repeat('x', 1025));
        self::assertNull($this->service()->current());

        $this->writeNotice('update', '2026-07-19T10:00:00Z', '2026-07-19T11:00:00Z');
        chmod($this->path, 0666);
        self::assertNull((new WallboardMaintenanceNoticeService($this->path))->current());
    }

    public function test_it_rejects_invalid_timestamps_and_unknown_kinds(): void
    {
        $this->writeNotice('update', '2026-02-30T10:00:00Z', '2026-07-19T11:00:00Z');
        self::assertNull($this->service()->current());

        $this->writeNotice('incident', '2026-07-19T10:00:00Z', '2026-07-19T11:00:00Z');
        self::assertNull($this->service()->current());
    }

    private function service(): WallboardMaintenanceNoticeService
    {
        return new WallboardMaintenanceNoticeService($this->path, false);
    }

    private function writeNotice(string $kind, string $startedAt, string $expiresAt): void
    {
        file_put_contents(
            $this->path,
            json_encode($this->payload($kind, $startedAt, $expiresAt), JSON_THROW_ON_ERROR),
        );
        clearstatcache(true, $this->path);
    }

    private function writeEstimatedNotice(
        string $startedAt,
        int $durationSeconds,
        string $estimatedCompletionAt,
        string $expiresAt,
    ): void {
        file_put_contents($this->path, json_encode([
            'version' => 2,
            'active' => true,
            'kind' => 'update',
            'started_at' => $startedAt,
            'estimated_duration_seconds' => $durationSeconds,
            'estimated_completion_at' => $estimatedCompletionAt,
            'expires_at' => $expiresAt,
        ], JSON_THROW_ON_ERROR));
        clearstatcache(true, $this->path);
    }

    /** @return array<string, mixed> */
    private function payload(string $kind, string $startedAt, string $expiresAt): array
    {
        return [
            'version' => 1,
            'active' => true,
            'kind' => $kind,
            'started_at' => $startedAt,
            'expires_at' => $expiresAt,
        ];
    }
}
