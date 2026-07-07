<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AddressBookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $search = trim((string) ($data['q'] ?? ''));

        $entries = User::query()
            ->select(['id', 'name', 'phone_number', 'home_city'])
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(function ($inner) use ($like): void {
                    $inner->where('name', 'like', $like)
                        ->orWhere('phone_number', 'like', $like)
                        ->orWhere('home_city', 'like', $like);
                });
            })
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'phone_number' => $user->phone_number,
                'city' => $user->home_city,
            ])
            ->values();

        return ApiResponse::success($entries);
    }
}
