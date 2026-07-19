<?php

namespace App\Http\Responses;

use App\Support\WallboardMediaContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class WallboardMediaResponse
{
    public static function make(
        Request $request,
        WallboardMediaContent $content,
        int $maxAgeSeconds,
    ): Response|StreamedResponse {
        $headers = [
            'Content-Type' => $content->contentType,
            'Content-Length' => (string) $content->byteSize,
            'ETag' => $content->etag,
            'Cache-Control' => 'private, max-age='.max(0, $maxAgeSeconds).', immutable',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if (self::matchesEtag((string) $request->header('If-None-Match', ''), $content->etag)) {
            unset($headers['Content-Length'], $headers['Content-Type']);

            return response('', 304, $headers);
        }

        $stream = Storage::disk($content->disk)->readStream($content->path);
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
