<?php

namespace App\Http\Responses;

use App\Support\OperationalRadarContent;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class OperationalRadarResponse
{
    private const MAX_AGE_SECONDS = 31_536_000;

    public static function make(Request $request, OperationalRadarContent $content): Response
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

        abort_if(strlen($content->body) !== $content->byteSize, 404);

        return response($content->body, 200, $headers);
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
