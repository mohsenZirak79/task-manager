<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadFileRequest;
use App\Http\Requests\UploadImageRequest;
use App\Http\Resources\MediaFileResource;
use App\Models\MediaFile;
use App\Services\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function file(UploadFileRequest $request): JsonResponse
    {
        $file = $this->uploadService->storeAttachment($request->file('file'), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'فایل با موفقیت بارگذاری شد.',
            'data' => ['file' => new MediaFileResource($file)],
        ], 201);
    }

    public function destroyFile(Request $request, MediaFile $file): JsonResponse
    {
        $this->uploadService->deleteUnattachedAttachment($file, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'فایل با موفقیت حذف شد.',
            'data' => [],
        ]);
    }
}
