<?php

namespace App\Services;

use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class MediaUploadService
{
    public function storeImage(UploadedFile $file, User $uploader): MediaFile
    {
        return $this->store($file, $uploader, 'public', 'avatars', 'image');
    }

    public function storeAttachment(UploadedFile $file, User $uploader): MediaFile
    {
        return $this->store(
            $file,
            $uploader,
            (string) config('uploads.files.disk'),
            (string) config('uploads.files.directory'),
            'attachment',
        );
    }

    public function deleteUnattachedAttachment(MediaFile $file, User $actor): void
    {
        if ($file->category !== 'attachment' || $file->uploaded_by !== $actor->id) {
            throw new HttpException(403, 'حذف این فایل مجاز نیست.');
        }

        if ($file->tasks()->exists()) {
            throw new HttpException(409, 'فایل متصل به تسک قابل حذف نیست.');
        }

        $directory = trim((string) config('uploads.files.directory'), '/');
        if (str_contains($file->path, '..') || ! str_starts_with($file->path, $directory.'/')) {
            throw new HttpException(409, 'مسیر فایل برای حذف معتبر نیست.');
        }

        $disk = $file->disk;
        $path = $file->path;
        $file->delete();
        Storage::disk($disk)->delete($path);
    }

    private function store(UploadedFile $file, User $uploader, string $disk, string $directory, string $category): MediaFile
    {
        $path = $file->store($directory, $disk);

        if (! $path) {
            throw new RuntimeException('ذخیره تصویر انجام نشد.');
        }

        try {
            return MediaFile::query()->create([
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
                'category' => $category,
                'size' => $file->getSize(),
                'uploaded_by' => $uploader->id,
            ]);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }
}
