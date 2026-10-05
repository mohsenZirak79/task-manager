<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateTaskPlanningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'planning_items' => ['sometimes', 'array', 'max:100'],
            'planning_items.*' => ['array:id,title,weight,sort_order'],
            'planning_items.*.id' => ['sometimes', 'integer', 'distinct', 'min:1'],
            'planning_items.*.title' => ['required', 'string', 'max:255'],
            'planning_items.*.weight' => ['required', 'numeric', 'min:0'],
            'planning_items.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'delete_planning_item_ids' => ['sometimes', 'array', 'max:100'],
            'delete_planning_item_ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['planning_items', 'delete_planning_item_ids']) as $field) {
                $validator->errors()->add($field, 'این مسیر فقط برای ویرایش برنامه‌ریزی است.');
            }
        }];
    }
}
