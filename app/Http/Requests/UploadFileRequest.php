<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class UploadFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                File::types(config('uploads.files.extensions'))
                    ->max((int) config('uploads.files.max_kilobytes')),
            ],
        ];
    }
}
