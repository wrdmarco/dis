<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\User;
use App\Services\DeploymentReportService;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DeploymentReportStorageCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_refresh_migrates_a_finalized_legacy_report_and_removes_its_sensitive_directory(): void
    {
        $actor = $this->user('legacy-report-refresh@example.test');
        $deployment = $this->deployment($actor, 'REPORT-LEGACY-REFRESH');
        $legacyPath = 'incident-reports/'.$deployment->id.'/afgerond-rapport.pdf';
        $generatedAt = now()->subHour();
        $finalizedAt = now()->subMinutes(30);
        $deployment->forceFill([
            'report_pdf_path' => $legacyPath,
            'report_generated_at' => $generatedAt,
            'report_finalized_at' => $finalizedAt,
        ])->save();
        $deployment->refresh();
        $generatedAt = $deployment->report_generated_at;
        $finalizedAt = $deployment->report_finalized_at;
        Storage::disk('local')->put($legacyPath, 'legacy-sensitive-pdf');
        Storage::disk('local')->put(
            'incident-reports/'.$deployment->id.'/gevoelige-bijlage.txt',
            'legacy-sensitive-metadata',
        );

        $service = app(DeploymentReportService::class);
        $canonicalPath = $service->refreshStored($deployment->refresh());

        $this->assertSame(
            'REPORT-LEGACY-REFRESH-Veilige-rapportopslag.pdf',
            $service->filename($deployment),
        );
        $this->assertSame(
            'deployment-reports/'.$deployment->id.'/report-legacy-refresh-veilige-rapportopslag.pdf',
            $canonicalPath,
        );
        Storage::disk('local')->assertExists((string) $canonicalPath);
        $this->assertSame('legacy-sensitive-pdf', Storage::disk('local')->get((string) $canonicalPath));
        $this->assertFalse(
            Storage::disk('local')->directoryExists('incident-reports/'.$deployment->id),
        );

        $deployment->refresh();
        $this->assertSame($canonicalPath, $deployment->report_pdf_path);
        $this->assertTrue($generatedAt->equalTo($deployment->report_generated_at));
        $this->assertTrue($finalizedAt->equalTo($deployment->report_finalized_at));
        $this->assertNull($deployment->report_generation_error);
    }

    public function test_refresh_bounds_a_long_canonical_report_filename_without_changing_the_download_name_rules(): void
    {
        $actor = $this->user('long-report-path@example.test');
        $deployment = $this->deployment($actor, 'REF-'.str_repeat('A', 251));
        $deployment->forceFill([
            'title' => str_repeat('B', 255),
            'report_pdf_path' => 'incident-reports/'.$deployment->id.'/bestaand-rapport.pdf',
            'report_generated_at' => now()->subHour(),
            'report_finalized_at' => now()->subMinutes(30),
        ])->save();
        Storage::disk('local')->put((string) $deployment->report_pdf_path, 'long-name-pdf');

        $service = app(DeploymentReportService::class);
        $canonicalPath = $service->refreshStored($deployment->refresh());
        $fullStorageBasename = strtolower($deployment->reference.'-'.$deployment->title);
        $storageDirectory = 'deployment-reports/'.$deployment->id.'/';
        $maxStorageBasenameBytes = 255 - strlen($storageDirectory) - strlen('.pdf');
        $expectedStorageFilename = substr(
            $fullStorageBasename,
            0,
            $maxStorageBasenameBytes - 17,
        )
            .'-'.substr(hash('sha256', $fullStorageBasename), 0, 16).'.pdf';

        $this->assertNotNull($canonicalPath);
        $this->assertSame('long-name-pdf', Storage::disk('local')->get((string) $canonicalPath));
        $this->assertSame(
            $storageDirectory.$expectedStorageFilename,
            $canonicalPath,
        );
        $this->assertLessThanOrEqual(255, strlen((string) $canonicalPath));
        $this->assertLessThanOrEqual(240, strlen(basename((string) $canonicalPath)));
        $this->assertStringStartsWith('REF-AAAA', $service->filename($deployment));
        $this->assertLessThanOrEqual(240, strlen($service->filename($deployment)));
    }

    public function test_a_later_canonical_access_retries_a_failed_legacy_cleanup(): void
    {
        $actor = $this->user('legacy-report-cleanup-retry@example.test');
        $deployment = $this->deployment($actor, 'REPORT-CLEANUP-RETRY');
        $canonicalPath = 'deployment-reports/'.$deployment->id.'/canoniek.pdf';
        $legacyDirectory = 'incident-reports/'.$deployment->id;
        $legacyPath = $legacyDirectory.'/achtergebleven.pdf';
        $deployment->forceFill([
            'report_pdf_path' => $canonicalPath,
            'report_generated_at' => now(),
            'report_finalized_at' => now(),
        ])->save();
        Storage::disk('local')->put($canonicalPath, 'canonical-sensitive-pdf');
        Storage::disk('local')->put($legacyPath, 'legacy-sensitive-pdf');

        $realDisk = Storage::disk('local');
        $failingDisk = new class($realDisk, $legacyDirectory)
        {
            public function __construct(
                private readonly object $disk,
                private readonly string $failingDirectory,
            ) {}

            public function path(string $path): string
            {
                return $this->disk->path($path);
            }

            public function deleteDirectory(string $directory): void
            {
                if ($directory === $this->failingDirectory) {
                    throw new \RuntimeException('Gesimuleerde tijdelijke cleanupfout.');
                }

                $this->disk->deleteDirectory($directory);
            }
        };

        Storage::set('local', $failingDisk);
        try {
            $this->assertSame(
                $canonicalPath,
                app(DeploymentReportService::class)->refreshStored($deployment->refresh()),
            );
        } finally {
            Storage::set('local', $realDisk);
        }
        Storage::disk('local')->assertExists($legacyPath);

        $this->assertSame(
            $canonicalPath,
            app(DeploymentReportService::class)->refreshStored($deployment->refresh()),
        );
        $this->assertFalse(Storage::disk('local')->directoryExists($legacyDirectory));
        Storage::disk('local')->assertExists($canonicalPath);
    }

    public function test_stored_pdf_rejects_traversal_and_another_deployments_legacy_directory(): void
    {
        $actor = $this->user('unsafe-report-path@example.test');
        $deployment = $this->deployment($actor, 'REPORT-PATH-OWNER');
        $otherDeployment = $this->deployment($actor, 'REPORT-PATH-OTHER');
        $foreignPath = 'incident-reports/'.$otherDeployment->id.'/ander-rapport.pdf';
        Storage::disk('local')->put($foreignPath, 'foreign-sensitive-pdf');

        $deployment->forceFill(['report_pdf_path' => $foreignPath])->save();
        $this->assertNull(app(DeploymentReportService::class)->storedPdfPath($deployment->refresh()));
        Storage::disk('local')->assertExists($foreignPath);

        $deployment->forceFill([
            'report_pdf_path' => 'incident-reports/'.$deployment->id.'/../../'.
                $otherDeployment->id.'/ander-rapport.pdf',
        ])->save();
        $this->assertNull(app(DeploymentReportService::class)->storedPdfPath($deployment->refresh()));
        Storage::disk('local')->assertExists($foreignPath);
    }

    public function test_report_directory_cleanup_rejects_an_unbounded_deployment_identity(): void
    {
        $sentinel = 'incident-reports/sentinel/blijven.pdf';
        Storage::disk('local')->put($sentinel, 'sensitive-sentinel');

        try {
            app(DeploymentReportService::class)->deleteStoredReportDirectories('../sentinel');
            $this->fail('Een onbegrensde rapportcleanupidentiteit werd geaccepteerd.');
        } catch (\InvalidArgumentException) {
            // Expected: only a real deployment ULID may own report directories.
        }

        Storage::disk('local')->assertExists($sentinel);
        $this->assertSame('sensitive-sentinel', Storage::disk('local')->get($sentinel));
    }

    public function test_deployment_delete_removes_only_its_canonical_and_legacy_report_directories(): void
    {
        Event::fake();
        $actor = $this->user('legacy-report-delete@example.test');
        $deployment = $this->deployment($actor, 'REPORT-DELETE');
        $otherDeployment = $this->deployment($actor, 'REPORT-PRESERVE');
        $legacyPath = 'incident-reports/'.$deployment->id.'/te-verwijderen.pdf';
        $foreignPath = 'incident-reports/'.$otherDeployment->id.'/te-bewaren.pdf';
        $deployment->forceFill([
            'report_pdf_path' => $legacyPath,
            'report_generated_at' => now(),
            'report_finalized_at' => now(),
        ])->save();

        Storage::disk('local')->put(
            'deployment-reports/'.$deployment->id.'/canoniek.pdf',
            'canonical-sensitive-pdf',
        );
        Storage::disk('local')->put($legacyPath, 'legacy-sensitive-pdf');
        Storage::disk('local')->put(
            'incident-reports/'.$deployment->id.'/gevoelige-bijlage.txt',
            'legacy-sensitive-metadata',
        );
        Storage::disk('local')->put($foreignPath, 'foreign-sensitive-pdf');

        app(DeploymentService::class)->delete($deployment, $actor);

        $this->assertDatabaseMissing('deployments', ['id' => $deployment->id]);
        $this->assertFalse(
            Storage::disk('local')->directoryExists('deployment-reports/'.$deployment->id),
        );
        $this->assertFalse(
            Storage::disk('local')->directoryExists('incident-reports/'.$deployment->id),
        );
        Storage::disk('local')->assertExists($foreignPath);
        $this->assertSame('foreign-sensitive-pdf', Storage::disk('local')->get($foreignPath));
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Rapporttest',
            'first_name' => 'Rapport',
            'last_name' => 'Test',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function deployment(User $creator, string $reference): Deployment
    {
        return Deployment::query()->create([
            'reference' => $reference,
            'title' => 'Veilige rapportopslag',
            'description' => 'Controle van begrensde rapportcleanup.',
            'priority' => 'normal',
            'status' => 'resolved',
            'is_test' => false,
            'created_by' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_email' => $creator->email,
            'opened_at' => now()->subHours(2),
            'closed_at' => now()->subHour(),
        ]);
    }
}
