<?php

namespace App\Http\Controllers;

use App\Http\Requests\Speech\ClearSpeechPreparedPhrasesRequest;
use App\Http\Requests\Speech\DeleteSpeechPreparedPhraseRequest;
use App\Http\Requests\Speech\IndexSpeechPreparedPhrasesRequest;
use App\Http\Requests\Speech\ManageSpeechPreparedPhrasePresetRequest;
use App\Http\Requests\Speech\RegenerateSpeechPreparedPhraseRequest;
use App\Http\Requests\Speech\SearchSpeechPreparedPhrasesRequest;
use App\Http\Requests\Speech\StoreSpeechPreparedPhrasesRequest;
use App\Http\Requests\Speech\ViewSpeechPreparedPhrasesRequest;
use App\Http\Responses\ApiResponse;
use App\Models\SpeechPreparedPhrase;
use App\Services\SpeechPreparedPhrasePresetService;
use App\Services\SpeechPreparedPhraseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminSpeechPreparedPhraseController extends Controller
{
    public function __construct(
        private readonly SpeechPreparedPhraseService $service,
        private readonly SpeechPreparedPhrasePresetService $presets,
    ) {}

    public function index(IndexSpeechPreparedPhrasesRequest $request): JsonResponse
    {
        return ApiResponse::paginated($this->service->paginate($request->validated()));
    }

    public function summary(ViewSpeechPreparedPhrasesRequest $request): JsonResponse
    {
        return ApiResponse::success($this->service->summary());
    }

    public function presets(ManageSpeechPreparedPhrasePresetRequest $request): JsonResponse
    {
        return ApiResponse::success($this->presets->all());
    }

    public function preparePreset(
        ManageSpeechPreparedPhrasePresetRequest $request,
        string $preset,
    ): JsonResponse {
        return ApiResponse::success(
            $this->presets->prepare($preset, $request->user()),
            202,
        );
    }

    public function search(SearchSpeechPreparedPhrasesRequest $request): JsonResponse
    {
        return ApiResponse::paginated($this->service->paginate($request->validated()));
    }

    public function store(StoreSpeechPreparedPhrasesRequest $request): JsonResponse
    {
        /** @var list<string> $values */
        $values = $request->validated('values');
        $items = $this->service->create(
            $request->string('kind')->toString(),
            $values,
            $request->user(),
        );

        return ApiResponse::success($items, 202);
    }

    public function audio(
        ViewSpeechPreparedPhrasesRequest $request,
        SpeechPreparedPhrase $speechPreparedPhrase,
    ): Response {
        $audio = $this->service->audio($speechPreparedPhrase);

        return $this->audioResponse($request, $audio['path'], $audio['etag']);
    }

    public function destroy(
        DeleteSpeechPreparedPhraseRequest $request,
        SpeechPreparedPhrase $speechPreparedPhrase,
    ): Response {
        $this->service->delete($speechPreparedPhrase, $request->user());

        return response()->noContent();
    }

    public function regenerate(
        RegenerateSpeechPreparedPhraseRequest $request,
        SpeechPreparedPhrase $speechPreparedPhrase,
    ): JsonResponse {
        return ApiResponse::success(
            $this->service->regenerate($speechPreparedPhrase, $request->user()),
            202,
        );
    }

    public function clear(ClearSpeechPreparedPhrasesRequest $request): JsonResponse
    {
        return ApiResponse::success($this->service->clear($request->user()));
    }

    private function audioResponse(Request $request, string $path, string $etag): Response
    {
        if (preg_match('/^"[A-Za-z0-9._-]{1,180}"$/D', $etag) !== 1) {
            throw new \RuntimeException('Prepared speech audio entity tag is invalid.');
        }
        $size = @filesize($path);
        if (! is_int($size) || $size < 1) {
            throw new \RuntimeException('Prepared speech audio size is invalid.');
        }
        $headers = [
            'Content-Type' => 'audio/mp4',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-store, private',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="speech.m4a"',
        ];
        $range = trim((string) $request->header('Range', ''));
        if ($range !== '' && ! $this->rangeIsSatisfiable($range, $size)) {
            return response('', 416, $headers + ['Content-Range' => 'bytes */'.$size]);
        }
        $response = response()->file($path, $headers);
        $response->isNotModified($request);

        return $response;
    }

    private function rangeIsSatisfiable(string $range, int $size): bool
    {
        if (preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $matches) !== 1
            || ($matches[1] === '' && $matches[2] === '')) {
            return false;
        }
        if ($matches[1] === '') {
            return (int) $matches[2] > 0;
        }
        $start = (int) $matches[1];
        $end = $matches[2] === '' ? $size - 1 : (int) $matches[2];

        return $start < $size && $end >= $start;
    }
}
