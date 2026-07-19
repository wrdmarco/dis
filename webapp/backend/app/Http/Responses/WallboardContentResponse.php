<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class WallboardContentResponse
{
    /** @param array<string, mixed> $payload */
    public static function make(Request $request, array $payload): JsonResponse|Response
    {
        $response = ApiResponse::success($payload);
        $body = (string) $response->getContent();
        $etag = '"'.hash('sha256', $body).'"';
        $headers = [
            'ETag' => $etag,
            'Cache-Control' => 'private, no-cache',
            'Vary' => 'Cookie',
        ];

        if (self::matchesEtag((string) $request->header('If-None-Match', ''), $etag)) {
            return new Response(null, Response::HTTP_NOT_MODIFIED, $headers);
        }

        $response->headers->add($headers);

        return $response;
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
