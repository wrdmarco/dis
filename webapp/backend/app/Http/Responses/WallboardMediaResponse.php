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
            'Accept-Ranges' => 'bytes',
        ];
        if (self::matchesEtag((string) $request->header('If-None-Match', ''), $content->etag)) {
            unset($headers['Content-Length'], $headers['Content-Type']);

            return response('', 304, $headers);
        }

        $range = self::range($request, $content);
        if ($range === false) {
            $headers['Content-Range'] = 'bytes */'.$content->byteSize;
            $headers['Content-Length'] = '0';

            return response('', 416, $headers);
        }

        $stream = Storage::disk($content->disk)->readStream($content->path);
        abort_if(! is_resource($stream), 404);

        if (is_array($range)) {
            [$start, $end] = $range;
            $length = $end - $start + 1;
            $headers['Content-Length'] = (string) $length;
            $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$content->byteSize;

            return response()->stream(static function () use ($stream, $start, $length): void {
                try {
                    if (fseek($stream, $start) !== 0) {
                        return;
                    }
                    $remaining = $length;
                    while ($remaining > 0 && ! feof($stream)) {
                        $chunk = fread($stream, min(1024 * 1024, $remaining));
                        if (! is_string($chunk) || $chunk === '') {
                            break;
                        }
                        echo $chunk;
                        $remaining -= strlen($chunk);
                    }
                } finally {
                    fclose($stream);
                }
            }, 206, $headers);
        }

        return response()->stream(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, $headers);
    }

    /** @return array{0: int, 1: int}|false|null */
    private static function range(Request $request, WallboardMediaContent $content): array|false|null
    {
        $header = trim((string) $request->header('Range', ''));
        if ($header === '') {
            return null;
        }
        $ifRange = trim((string) $request->header('If-Range', ''));
        if ($ifRange !== '' && ! hash_equals($content->etag, $ifRange)) {
            return null;
        }
        if (preg_match('/^bytes=(\d*)-(\d*)$/D', $header, $matches) !== 1
            || ($matches[1] === '' && $matches[2] === '')) {
            return false;
        }

        if ($matches[1] === '') {
            $suffixLength = (int) $matches[2];
            if ($suffixLength < 1) {
                return false;
            }
            $start = max(0, $content->byteSize - $suffixLength);

            return [$start, $content->byteSize - 1];
        }

        $start = (int) $matches[1];
        $end = $matches[2] === '' ? $content->byteSize - 1 : (int) $matches[2];
        if ($start < 0 || $start >= $content->byteSize || $end < $start) {
            return false;
        }

        return [$start, min($end, $content->byteSize - 1)];
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
