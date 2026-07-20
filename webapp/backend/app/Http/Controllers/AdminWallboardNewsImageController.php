<?php

namespace App\Http\Controllers;

use App\Services\WallboardNewsService;
use Symfony\Component\HttpFoundation\Response;

final class AdminWallboardNewsImageController extends Controller
{
    public function __construct(
        private readonly WallboardNewsService $newsImages,
    ) {}

    public function show(string $image): Response
    {
        $result = $this->newsImages->image($image);
        abort_if($result === null, 404);

        return response($result['body'], 200, [
            'Content-Type' => $result['content_type'],
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
