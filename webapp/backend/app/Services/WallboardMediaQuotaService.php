<?php

namespace App\Services;

use App\Repositories\WallboardMediaAssetRepository;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class WallboardMediaQuotaService
{
    public function __construct(private readonly WallboardMediaAssetRepository $assets) {}

    public function reserve(int $bytes, Closure $callback): mixed
    {
        return $this->reserveUnderLock($bytes, true, $callback);
    }

    public function reserveAdditionalBytes(int $bytes, Closure $callback): mixed
    {
        return $this->reserveUnderLock($bytes, false, $callback);
    }

    private function reserveUnderLock(int $bytes, bool $checkAssetCount, Closure $callback): mixed
    {
        try {
            return Cache::lock(
                'wallboard-media:quota',
                max(5, (int) config('wallboard_media.quota_lock_seconds', 30)),
            )->block(
                max(1, (int) config('wallboard_media.quota_wait_seconds', 5)),
                function () use ($bytes, $checkAssetCount, $callback): mixed {
                    $this->assertCapacity($bytes, $checkAssetCount);

                    return $callback();
                },
            );
        } catch (LockTimeoutException) {
            throw new HttpException(503, 'De mediaopslag is bezig. Probeer het zo opnieuw.');
        } catch (ValidationException|HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new HttpException(503, 'De capaciteit van de mediaopslag kon niet veilig worden gecontroleerd.', $exception);
        }
    }

    private function assertCapacity(int $incomingBytes, bool $checkAssetCount): void
    {
        $maximumBytes = max(1, (int) config('wallboard_media.max_total_bytes', 5 * 1024 * 1024 * 1024));
        if ($incomingBytes < 0 || $this->assets->activeByteSize() > $maximumBytes - $incomingBytes) {
            throw ValidationException::withMessages([
                'image' => ['De opslaglimiet voor wallboardmedia is bereikt.'],
            ]);
        }
        $maximumAssets = max(1, (int) config('wallboard_media.max_assets', 5000));
        if ($checkAssetCount && $this->assets->activeCount() >= $maximumAssets) {
            throw ValidationException::withMessages([
                'image' => ['Het maximale aantal wallboardafbeeldingen is bereikt.'],
            ]);
        }

        $disk = Storage::disk((string) config('wallboard_media.disk', 'local'));
        $root = trim((string) config('wallboard_media.root', 'wallboard-media'), '/');
        $absoluteRoot = $disk->path($root);
        if (! is_dir($absoluteRoot) && ! @mkdir($absoluteRoot, 0770, true)) {
            throw new HttpException(503, 'De mediaopslag is niet beschikbaar.');
        }
        $freeBytes = @disk_free_space($absoluteRoot);
        $minimumFree = max(0, (int) config('wallboard_media.minimum_free_bytes', 1024 * 1024 * 1024));
        if (! is_float($freeBytes) || $freeBytes < $minimumFree + $incomingBytes) {
            throw ValidationException::withMessages([
                'image' => ['Er is onvoldoende vrije schijfruimte voor deze afbeelding.'],
            ]);
        }
    }
}
