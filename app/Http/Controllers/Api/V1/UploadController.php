<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadImageRequest;
use App\Http\Resources\MediaFileResource;
use App\Services\MediaUploadService;
use Illuminate\Http\JsonResponse;

class UploadController extends Controller
{
    public function __construct(private readonly MediaUploadService $uploadService) {}

    public function image(UploadImageRequest $request): JsonResponse
    {
        $file = $this->uploadService->storeImage($request->file('file'), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'تصویر با موفقیت بارگذاری شد.',
            'data' => ['file' => new MediaFileResource($file)],
        ], 201);
    }
}
