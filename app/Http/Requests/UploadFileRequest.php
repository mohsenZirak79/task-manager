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
                'required_without:files',
                File::types(config('uploads.files.extensions'))
                    ->max((int) config('uploads.files.max_kilobytes')),
            ],
            'files' => ['required_without:file', 'array', 'min:1', 'max:20'],
            'files.*' => [File::types(config('uploads.files.extensions'))->max((int) config('uploads.files.max_kilobytes'))],
        ];
    }
}
