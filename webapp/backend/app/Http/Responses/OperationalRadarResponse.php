<?php

namespace App\Http\Responses;

use App\Support\OperationalRadarContent;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OperationalRadarResponse
{
    private const MAX_AGE_SECONDS = 31_536_000;

    public static function make(Request $request, OperationalRadarContent $content): Response|StreamedResponse
    {
        $etag = $content->etag();
        $headers = [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) $content->byteSize,
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age='.self::MAX_AGE_SECONDS.', immutable',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (self::matchesEtag((string) $request->header('If-None-Match', ''), $etag)) {
            unset($headers['Content-Length'], $headers['Content-Type']);

            return response('', 304, $headers);
        }

        $stream = @fopen($content->path, 'rb');
        abort_if(! is_resource($stream), 404);

        return response()->stream(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, $headers);
    }

    private static function matchesEtag(string $header, string $etag): bool
    {
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || $candidate === $etag || $candidate === 'W/'.$etag) {
                return true;
            }
        }

        return false;
    }
}
