<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required_without:files', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'files' => ['required_without:file', 'array', 'min:1', 'max:20'],
            'files.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
