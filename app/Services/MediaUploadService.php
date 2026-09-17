<?php

namespace App\Services;

use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class MediaUploadService
{
    public function storeImage(UploadedFile $file, User $uploader): MediaFile
    {
        $disk = 'public';
        $path = $file->store('avatars', $disk);

        if (! $path) {
            throw new RuntimeException('ذخیره تصویر انجام نشد.');
        }

        try {
            return MediaFile::query()->create([
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $uploader->id,
            ]);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }
}
