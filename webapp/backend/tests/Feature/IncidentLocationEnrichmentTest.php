<?php

namespace Tests\Feature;

use App\Jobs\ResolveIncidentLocation;
use App\Models\Incident;
use App\Models\User;
use App\Services\IncidentLocationEnrichmentService;
use App\Services\IncidentService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;
use Throwable;

final class IncidentLocationEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'dis.incident_location.enabled' => true,
            'dis.incident_location.wfs_url' => 'https://service.pdok.nl/kadaster/brk-bestuurlijke-gebieden/wfs/v1_0',
            'dis.incident_location.country_url' => 'https://gisco-services.ec.europa.eu/id/country',
            'dis.incident_location.connect_timeout_seconds' => 1,
            'dis.incident_location.timeout_seconds' => 2,
            'dis.incident_location.backfill_batch' => 3,
        ]);
    }

    public function test_pdok_feature_is_allowlisted_persisted_and_requested_with_latitude_longitude_axis_order(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->featureCollection([
            ['code' => '26', 'name' => 'Utrecht'],
        ]), 200, ['Content-Type' => 'application/gml+xml'])]);
        $incident = $this->incident('PROVINCE-VALID', 52.0907, 5.1214);

        self::assertTrue(app(IncidentLocationEnrichmentService::class)->resolve($incident));

        $incident->refresh();
        self::assertSame('26', $incident->province_code);
        self::assertSame('Utrecht', $incident->province_name);
        self::assertSame(IncidentLocationEnrichmentService::SOURCE, $incident->province_source);
        self::assertNotNull($incident->province_resolved_at);
        self::assertSame('NL', $incident->country_code);
        self::assertSame('Nederland', $incident->country_name);
        self::assertSame(IncidentLocationEnrichmentService::SOURCE, $incident->country_source);
        self::assertNotNull($incident->country_resolved_at);
        Http::assertSentCount(1);
        Http::assertSent(static function (Request $request): bool {
            return str_starts_with(
                $request->url(),
                'https://service.pdok.nl/kadaster/brk-bestuurlijke-gebieden/wfs/v1_0?',
            )
                && $request['service'] === 'WFS'
                && $request['version'] === '2.0.0'
                && $request['request'] === 'GetFeature'
                && $request['typeNames'] === 'bestuurlijkegebieden:Provinciegebied'
                && $request['propertyName'] === 'naam,code'
                && (int) $request['count'] === 2
                && $request['srsName'] === 'urn:ogc:def:crs:EPSG::4326'
                && $request['bbox'] === '52.0906900,5.1213900,52.0907100,5.1214100,urn:ogc:def:crs:EPSG::4326'
                && $request->hasHeader('Accept-Encoding', 'identity');
        });
    }

    #[DataProvider('unsafeProviderUrls')]
    public function test_configured_pdok_url_rejects_ssrf_and_plaintext_variants(string $url): void
    {
        config(['dis.incident_location.wfs_url' => $url]);
        Http::preventStrayRequests();
        Http::fake();

        try {
            app(IncidentLocationEnrichmentService::class)->resolve(
                $this->incident('PROVINCE-UNSAFE-'.strtolower((string) str()->ulid()), 52.0907, 5.1214),
            );
            self::fail('An untrusted provider URL must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('A configured incident location enrichment URL is invalid.', $exception->getMessage());
        }

        Http::assertSentCount(0);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeProviderUrls(): iterable
    {
        yield 'plaintext' => ['http://service.pdok.nl/kadaster/brk-bestuurlijke-gebieden/wfs/v1_0'];
        yield 'userinfo' => ['https://user:secret@service.pdok.nl/kadaster/brk-bestuurlijke-gebieden/wfs/v1_0'];
        yield 'query' => ['https://service.pdok.nl/kadaster/brk-bestuurlijke-gebieden/wfs/v1_0?target=internal'];
        yield 'fragment' => ['https://service.pdok.nl/kadaster/brk-bestuurlijke-gebieden/wfs/v1_0#internal'];
        yield 'wrong host' => ['https://example.test/kadaster/brk-bestuurlijke-gebieden/wfs/v1_0'];
        yield 'wrong port' => ['https://service.pdok.nl:8443/kadaster/brk-bestuurlijke-gebieden/wfs/v1_0'];
    }

    public function test_configured_gisco_url_is_bound_to_the_official_https_origin(): void
    {
        config(['dis.incident_location.country_url' => 'https://service.pdok.nl/id/country']);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->featureCollection([]), 200)]);

        $this->expectException(RuntimeException::class);
        try {
            app(IncidentLocationEnrichmentService::class)->resolve(
                $this->incident('COUNTRY-UNSAFE', 50.8503, 4.3517),
            );
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_zero_or_multiple_pdok_matches_are_unknown_while_gisco_resolves_country(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push($this->featureCollection([]), 200)
            ->push($this->countryResult('BE'), 200)
            ->push($this->featureCollection([
                ['code' => '26', 'name' => 'Utrecht'],
                ['code' => '25', 'name' => 'Gelderland'],
            ]), 200)
            ->push($this->countryResult('DE'), 200);

        foreach ([
            [$this->incident('PROVINCE-ZERO', 50.8503, 4.3517), 'BE', 'België'],
            [$this->incident('PROVINCE-MULTIPLE', 52.5200, 13.4050), 'DE', 'Duitsland'],
        ] as [$incident, $countryCode, $countryName]) {
            self::assertTrue(app(IncidentLocationEnrichmentService::class)->resolve($incident));
            $incident->refresh();
            self::assertNull($incident->province_code);
            self::assertNull($incident->province_name);
            self::assertSame(IncidentLocationEnrichmentService::SOURCE, $incident->province_source);
            self::assertNotNull($incident->province_resolved_at);
            self::assertSame($countryCode, $incident->country_code);
            self::assertSame($countryName, $incident->country_name);
            self::assertSame(IncidentLocationEnrichmentService::COUNTRY_SOURCE, $incident->country_source);
            self::assertNotNull($incident->country_resolved_at);
        }

        Http::assertSent(static fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://gisco-services.ec.europa.eu/id/country?',
        )
            && (float) $request['x'] === 4.3517
            && (float) $request['y'] === 50.8503
            && (int) $request['epsg'] === 4326
            && (int) $request['year'] === 2024
            && $request['format'] === 'json'
            && $request['geometry'] === 'no');
    }

    public function test_oversized_response_throws_without_persisting_a_false_resolution(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(str_repeat('x', IncidentLocationEnrichmentService::MAX_RESPONSE_BYTES + 1), 200)]);
        $incident = $this->incident('PROVINCE-OVERSIZED', 52.0907, 5.1214);

        $this->expectException(RuntimeException::class);
        try {
            app(IncidentLocationEnrichmentService::class)->resolve($incident);
        } finally {
            $incident->refresh();
            self::assertNull($incident->province_code);
            self::assertNull($incident->province_name);
            self::assertNull($incident->province_source);
            self::assertNull($incident->province_resolved_at);
        }
    }

    public function test_encoded_provider_responses_are_rejected_before_body_buffering_or_parsing(): void
    {
        $method = new \ReflectionMethod(IncidentLocationEnrichmentService::class, 'boundedHttpOptions');
        $options = $method->invoke(app(IncidentLocationEnrichmentService::class));

        self::assertFalse($options['decode_content']);
        $this->expectException(RuntimeException::class);
        $options['on_headers'](new PsrResponse(200, ['Content-Encoding' => 'gzip']));
    }

    public function test_http_parser_and_allowlist_errors_never_persist_a_province(): void
    {
        $scenarios = [
            ['status' => 503, 'body' => '<error/>'],
            ['status' => 200, 'body' => '<not-xml'],
            ['status' => 200, 'body' => '<?xml version="1.0"?><ExceptionReport><Exception/></ExceptionReport>'],
            ['status' => 200, 'body' => $this->featureCollection([['code' => '99', 'name' => 'Atlantis']])],
            ['status' => 200, 'body' => '<!DOCTYPE x [<!ENTITY y SYSTEM "file:///etc/passwd">]><FeatureCollection/>'],
        ];

        foreach ($scenarios as $index => $scenario) {
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response($scenario['body'], $scenario['status'])]);
            $incident = $this->incident('PROVINCE-ERROR-'.$index, 52.0907, 5.1214);

            try {
                app(IncidentLocationEnrichmentService::class)->resolve($incident);
                self::fail('A malformed, failed, unsafe, or non-allowlisted response must throw.');
            } catch (Throwable) {
                $incident->refresh();
                self::assertNull($incident->province_code);
                self::assertNull($incident->province_name);
                self::assertNull($incident->province_source);
                self::assertNull($incident->province_resolved_at);
            }
        }
    }

    public function test_gisco_errors_and_oversized_json_leave_country_unresolved_for_retry(): void
    {
        $scenarios = [
            ['status' => 503, 'body' => '[]'],
            ['status' => 200, 'body' => '{not-json'],
            ['status' => 200, 'body' => str_repeat('x', IncidentLocationEnrichmentService::MAX_RESPONSE_BYTES + 1)],
            ['status' => 200, 'body' => json_encode([
                ['attributes' => ['id' => 'BE', 'OBJECTID' => 'BE']],
                ['attributes' => ['id' => 'DE', 'OBJECTID' => 'DE']],
            ], JSON_THROW_ON_ERROR)],
            ['status' => 200, 'body' => '[{"attributes":{"id":"BE","OBJECTID":"NL"}}]'],
        ];

        foreach ($scenarios as $index => $scenario) {
            Http::preventStrayRequests();
            $requestNumber = 0;
            Http::fake(function () use (&$requestNumber, $scenario) {
                $requestNumber++;

                return $requestNumber === 1
                    ? Http::response($this->featureCollection([]), 200)
                    : Http::response($scenario['body'], $scenario['status']);
            });
            $incident = $this->incident('COUNTRY-ERROR-'.$index, 50.8503, 4.3517);

            try {
                app(IncidentLocationEnrichmentService::class)->resolve($incident);
                self::fail('A failed or malformed GISCO response must throw.');
            } catch (Throwable) {
                $incident->refresh();
                self::assertNotNull($incident->province_resolved_at, 'Scenario '.$index.' must preserve the completed PDOK lookup.');
                self::assertNull($incident->province_code);
                self::assertNull($incident->country_code);
                self::assertNull($incident->country_name);
                self::assertNull($incident->country_source);
                self::assertNull($incident->country_resolved_at);
            }
        }
    }

    public function test_country_outside_allowlist_and_missing_coordinates_resolve_as_unknown(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push($this->featureCollection([]), 200)
            ->push($this->countryResult('FR'), 200);
        $outside = $this->incident('COUNTRY-OUTSIDE', 48.8566, 2.3522);

        self::assertTrue(app(IncidentLocationEnrichmentService::class)->resolve($outside));

        $outside->refresh();
        self::assertNull($outside->country_code);
        self::assertNull($outside->country_name);
        self::assertSame(IncidentLocationEnrichmentService::COUNTRY_SOURCE, $outside->country_source);
        self::assertNotNull($outside->country_resolved_at);

        Http::fake([]);
        $missing = $this->incident('COUNTRY-MISSING');
        self::assertTrue(app(IncidentLocationEnrichmentService::class)->resolve($missing));
        $missing->refresh();
        self::assertNull($missing->province_code);
        self::assertNull($missing->province_source);
        self::assertNotNull($missing->province_resolved_at);
        self::assertNull($missing->country_code);
        self::assertNull($missing->country_source);
        self::assertNotNull($missing->country_resolved_at);
    }

    public function test_transport_exception_is_sanitized_before_the_job_can_store_it(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::failedConnection('secret URI included 52.0907,5.1214')]);
        $incident = $this->incident('LOCATION-TRANSPORT', 52.0907, 5.1214);

        try {
            app(IncidentLocationEnrichmentService::class)->resolve($incident);
            self::fail('A failed connection must throw.');
        } catch (RuntimeException $exception) {
            self::assertSame('Incident province WFS transport failed.', $exception->getMessage());
            self::assertStringNotContainsString('52.0907', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function test_coordinate_race_does_not_persist_a_stale_pdok_result(): void
    {
        $incident = $this->incident('PROVINCE-RACE', 52.0907, 5.1214);
        Http::preventStrayRequests();
        Http::fake(function () use ($incident) {
            DB::table('incidents')->where('id', $incident->id)->update([
                'latitude' => 53.2194000,
                'longitude' => 6.5665000,
            ]);

            return Http::response($this->featureCollection([
                ['code' => '26', 'name' => 'Utrecht'],
            ]), 200);
        });

        self::assertFalse(app(IncidentLocationEnrichmentService::class)->resolve($incident));

        $incident->refresh();
        self::assertSame('53.2194000', $incident->latitude);
        self::assertSame('6.5665000', $incident->longitude);
        self::assertNull($incident->province_code);
        self::assertNull($incident->province_resolved_at);
        self::assertNull($incident->country_code);
        self::assertNull($incident->country_resolved_at);

        Queue::fake();
        $this->artisan('dis:backfill-incident-locations', ['--batch' => '2'])->assertSuccessful();
        Queue::assertPushed(
            ResolveIncidentLocation::class,
            fn (ResolveIncidentLocation $job): bool => $job->incidentId === (string) $incident->id,
        );
    }

    public function test_disabled_enrichment_and_test_incidents_never_call_or_queue_external_providers(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
        $actor = $this->user();

        config(['dis.incident_location.enabled' => false]);
        $disabled = app(IncidentService::class)->create([
            'title' => 'Uitgeschakelde verrijking',
            'priority' => 'normal',
            'status' => 'draft',
            'is_test' => false,
            'location_label' => 'Utrecht',
            'latitude' => 52.0907,
            'longitude' => 5.1214,
        ], $actor);
        self::assertTrue(app(IncidentLocationEnrichmentService::class)->resolve($disabled));
        Queue::assertNotPushed(ResolveIncidentLocation::class);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'service.pdok.nl')
            || str_contains($request->url(), 'gisco-services.ec.europa.eu'));
        $disabled->delete();

        config(['dis.incident_location.enabled' => true]);
        $testIncident = app(IncidentService::class)->create([
            'title' => 'Testincident zonder verrijking',
            'priority' => 'normal',
            'status' => 'draft',
            'is_test' => true,
            'location_label' => 'Utrecht',
            'latitude' => 52.0907,
            'longitude' => 5.1214,
        ], $actor);
        self::assertTrue(app(IncidentLocationEnrichmentService::class)->resolve($testIncident));
        $this->artisan('dis:backfill-incident-locations', ['--batch' => '3'])->assertSuccessful();
        Queue::assertNotPushed(ResolveIncidentLocation::class);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'service.pdok.nl')
            || str_contains($request->url(), 'gisco-services.ec.europa.eu'));
    }

    public function test_incident_create_and_coordinate_change_wait_for_the_isolated_scheduler_queue(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response([], 503)]);
        $actor = $this->user();

        $created = app(IncidentService::class)->create([
            'title' => 'Provincie bij creatie',
            'priority' => 'normal',
            'status' => 'draft',
            'is_test' => false,
            'location_label' => 'Utrecht',
            'latitude' => 52.0907,
            'longitude' => 5.1214,
        ], $actor);

        Queue::assertNotPushed(ResolveIncidentLocation::class);
        $this->artisan('dis:backfill-incident-locations', ['--batch' => '2'])->assertSuccessful();
        Queue::assertPushed(
            ResolveIncidentLocation::class,
            fn (ResolveIncidentLocation $job): bool => $job->incidentId === (string) $created->id
                && $job->queue === 'incident-enrichment',
        );

        $created->forceFill([
            'province_code' => '26',
            'province_name' => 'Utrecht',
            'province_source' => IncidentLocationEnrichmentService::SOURCE,
            'province_resolved_at' => now(),
            'country_code' => 'NL',
            'country_name' => 'Nederland',
            'country_source' => IncidentLocationEnrichmentService::SOURCE,
            'country_resolved_at' => now(),
            'location_enrichment_attempted_at' => now(),
        ])->save();
        Queue::fake();

        app(IncidentService::class)->update($created->refresh(), [
            'latitude' => 53.2194,
            'longitude' => 6.5665,
        ], $actor);

        $created->refresh();
        self::assertNull($created->province_code);
        self::assertNull($created->province_name);
        self::assertNull($created->province_source);
        self::assertNull($created->province_resolved_at);
        self::assertNull($created->country_code);
        self::assertNull($created->country_name);
        self::assertNull($created->country_source);
        self::assertNull($created->country_resolved_at);
        self::assertNull($created->location_enrichment_attempted_at);
        Queue::assertNotPushed(ResolveIncidentLocation::class);
        $this->artisan('dis:backfill-incident-locations', ['--batch' => '2'])->assertSuccessful();
        Queue::assertPushed(
            ResolveIncidentLocation::class,
            fn (ResolveIncidentLocation $job): bool => $job->incidentId === (string) $created->id
                && $job->queue === 'incident-enrichment',
        );

        $created->forceFill([
            'province_code' => '20',
            'province_name' => 'Groningen',
            'province_source' => IncidentLocationEnrichmentService::SOURCE,
            'province_resolved_at' => now(),
            'country_code' => 'NL',
            'country_name' => 'Nederland',
            'country_source' => IncidentLocationEnrichmentService::SOURCE,
            'country_resolved_at' => now(),
            'location_enrichment_attempted_at' => now(),
        ])->save();
        Queue::fake();

        app(IncidentService::class)->update($created->refresh(), [
            'latitude' => '53.2194000',
            'longitude' => '6.5665000',
        ], $actor);

        $created->refresh();
        self::assertSame('20', $created->province_code);
        self::assertNotNull($created->province_resolved_at);
        self::assertSame('NL', $created->country_code);
        self::assertNotNull($created->country_resolved_at);
        Queue::assertNotPushed(ResolveIncidentLocation::class);
    }

    public function test_incident_write_path_never_touches_the_location_queue_or_unique_cache(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')
            ->never();
        $actor = $this->user();

        $created = app(IncidentService::class)->create([
            'title' => 'Operationeel veilig bij queue-uitval',
            'priority' => 'normal',
            'status' => 'draft',
            'is_test' => false,
            'location_label' => 'Utrecht',
            'latitude' => 52.0907,
            'longitude' => 5.1214,
        ], $actor);

        self::assertDatabaseHas('incidents', ['id' => $created->id]);
        self::assertNull($created->refresh()->location_enrichment_attempted_at);
    }

    public function test_backfill_is_bounded_and_scheduled_with_cluster_guards(): void
    {
        Queue::fake();
        $first = $this->incident('PROVINCE-BACKFILL-1', 52.1, 5.1);
        $first->forceFill([
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ])->save();
        $second = $this->incident('PROVINCE-BACKFILL-2', 52.2, 5.2);
        $second->forceFill([
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ])->save();
        $third = $this->incident('PROVINCE-BACKFILL-3', 52.3, 5.3);
        $third->forceFill([
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ])->save();
        $this->incident('PROVINCE-BACKFILL-NO-COORDS');
        $this->incident('PROVINCE-BACKFILL-RESOLVED', 52.4, 5.4)->forceFill([
            'province_source' => IncidentLocationEnrichmentService::SOURCE,
            'province_resolved_at' => now(),
            'country_source' => IncidentLocationEnrichmentService::SOURCE,
            'country_resolved_at' => now(),
        ])->save();

        config(['dis.incident_location.backfill_batch' => 1]);
        $this->artisan('dis:backfill-incident-locations')
            ->expectsOutput('Queued location enrichment for 2 incident(s).')
            ->assertSuccessful();

        Queue::assertPushed(ResolveIncidentLocation::class, 2);
        Queue::assertPushed(
            ResolveIncidentLocation::class,
            fn (ResolveIncidentLocation $job): bool => $job->incidentId === (string) $first->id,
        );
        Queue::assertPushed(
            ResolveIncidentLocation::class,
            fn (ResolveIncidentLocation $job): bool => $job->incidentId === (string) $third->id,
        );
        self::assertNotNull($first->refresh()->location_enrichment_attempted_at);
        self::assertNull($second->refresh()->location_enrichment_attempted_at);
        self::assertNotNull($third->refresh()->location_enrichment_attempted_at);

        Queue::fake();
        $this->artisan('dis:backfill-incident-locations', ['--batch' => '2'])->assertSuccessful();
        Queue::assertPushed(
            ResolveIncidentLocation::class,
            fn (ResolveIncidentLocation $job): bool => $job->incidentId === (string) $second->id
                && $job->queue === 'incident-enrichment',
        );
        $this->artisan('dis:backfill-incident-locations', ['--batch' => '1'])
            ->assertExitCode(Command::INVALID);
        $this->artisan('dis:backfill-incident-locations', ['--batch' => '101'])
            ->assertExitCode(Command::INVALID);
        $this->artisan('dis:backfill-incident-locations', ['--batch' => '4'])
            ->assertExitCode(Command::INVALID);

        $job = new ResolveIncidentLocation((string) $third->id);
        self::assertInstanceOf(ShouldBeUnique::class, $job);
        self::assertSame(1, $job->tries);
        self::assertSame(21600, $job->uniqueFor);

        $event = collect(app(Schedule::class)->events())->first(
            fn ($candidate): bool => str_contains((string) $candidate->command, 'dis:backfill-incident-locations'),
        );
        self::assertNotNull($event);
        self::assertTrue($event->onOneServer);
        self::assertTrue($event->withoutOverlapping);
    }

    public function test_backfill_uses_six_hour_cooldown_oldest_due_fairness_and_provider_failures_do_not_retry(): void
    {
        Queue::fake();
        $olderDue = $this->incident('PROVINCE-DUE-OLD', 52.1, 5.1);
        $olderDue->forceFill([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
            'location_enrichment_attempted_at' => now()->subHours(7),
        ])->save();
        $newNeverAttempted = $this->incident('PROVINCE-NEW-NULL', 52.2, 5.2);
        $recentAttempt = $this->incident('PROVINCE-RECENT', 52.3, 5.3);
        $recentAttempt->forceFill(['location_enrichment_attempted_at' => now()->subHour()])->save();

        $this->artisan('dis:backfill-incident-locations', ['--batch' => '2'])->assertSuccessful();
        Queue::assertPushed(ResolveIncidentLocation::class, 2);
        Queue::assertPushed(
            ResolveIncidentLocation::class,
            fn (ResolveIncidentLocation $job): bool => $job->incidentId === (string) $olderDue->id,
        );
        Queue::assertPushed(
            ResolveIncidentLocation::class,
            fn (ResolveIncidentLocation $job): bool => $job->incidentId === (string) $newNeverAttempted->id,
        );
        self::assertNotNull($newNeverAttempted->refresh()->location_enrichment_attempted_at);
        self::assertNotNull($recentAttempt->refresh()->location_enrichment_attempted_at);

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('<error/>', 503)]);
        $job = new ResolveIncidentLocation((string) $olderDue->id);
        $job->handle(app(IncidentLocationEnrichmentService::class));
        self::assertNotNull($olderDue->refresh()->location_enrichment_attempted_at);
    }

    public function test_queue_outage_is_best_effort_and_does_not_start_the_six_hour_cooldown(): void
    {
        $incident = $this->incident('PROVINCE-QUEUE-OUTAGE', 52.1, 5.1);
        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('private queue connection details'));

        $this->artisan('dis:backfill-incident-locations', ['--batch' => '2'])
            ->expectsOutput('Queued location enrichment for 0 incident(s).')
            ->assertSuccessful();

        self::assertNull($incident->refresh()->location_enrichment_attempted_at);
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'Provincie Beheerder',
            'first_name' => 'Provincie',
            'last_name' => 'Beheerder',
            'email' => 'province@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
        ]);
    }

    private function incident(
        string $reference,
        ?float $latitude = null,
        ?float $longitude = null,
    ): Incident {
        $actor = User::query()->first() ?? $this->user();

        return Incident::query()->create([
            'reference' => $reference,
            'title' => 'Provincietest',
            'priority' => 'normal',
            'status' => 'draft',
            'is_test' => false,
            'location_label' => 'Testlocatie',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'created_by' => $actor->id,
            'created_by_name' => $actor->name,
            'created_by_email' => $actor->email,
            'opened_at' => now(),
        ]);
    }

    /** @param list<array{code: string, name: string}> $features */
    private function featureCollection(array $features): string
    {
        $members = array_map(
            static fn (array $feature): string => sprintf(
                '<wfs:member><bg:Provinciegebied><bg:naam>%s</bg:naam><bg:code>%s</bg:code></bg:Provinciegebied></wfs:member>',
                htmlspecialchars($feature['name'], ENT_XML1),
                htmlspecialchars($feature['code'], ENT_XML1),
            ),
            $features,
        );

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<wfs:FeatureCollection xmlns:wfs="http://www.opengis.net/wfs/2.0" xmlns:bg="http://www.kadaster.nl/schemas/brk-bestuurlijke-gebieden/v1_0">'
            .implode('', $members)
            .'</wfs:FeatureCollection>';
    }

    private function countryResult(string $code, ?string $objectId = null): string
    {
        return json_encode([[
            'attributes' => [
                'id' => $code,
                'OBJECTID' => $objectId ?? $code,
            ],
        ]], JSON_THROW_ON_ERROR);
    }
}
