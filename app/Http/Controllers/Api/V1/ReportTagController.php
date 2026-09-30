<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexTaskTagRequest;
use App\Http\Resources\TaskTagResource;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;

class ReportTagController extends Controller
{
    public function index(IndexTaskTagRequest $request): JsonResponse
    {
        $tags = Tag::query()
            ->when($request->validated('search'), fn ($query, $search) => $query->where('title', 'like', "%{$search}%"))
            ->orderBy('title')
            ->paginate($request->validated('per_page', 20));

        return response()->json([
            'success' => true,
            'message' => 'لیست تگ‌های گزارش',
            'data' => [
                'items' => TaskTagResource::collection($tags->items()),
                'meta' => [
                    'current_page' => $tags->currentPage(),
                    'last_page' => $tags->lastPage(),
                    'per_page' => $tags->perPage(),
                    'total' => $tags->total(),
                ],
            ],
        ]);
    }
}
