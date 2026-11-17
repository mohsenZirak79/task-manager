<?php

namespace App\Http\Requests\Concerns;

trait ValidatesListFilters
{
    protected function prepareForValidation(): void
    {
        $values = $this->all();
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
                $values[$key] = $value === '' ? null : $value;
            }
        }
        $this->merge($values);
    }

    protected function textFilterRules(array $names): array
    {
        return array_fill_keys($names, ['nullable', 'string', 'max:255']);
    }

    protected function paginationRules(): array
    {
        return ['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,100']];
    }

    protected function dateRangeRules(string $from, string $to): array
    {
        return [
            $from => ['nullable', 'date_format:Y-m-d'],
            $to => ['nullable', 'date_format:Y-m-d', ...($this->filled($from) ? ['after_or_equal:'.$from] : [])],
        ];
    }

    protected function numberRangeRules(string $min, string $max, bool $integer = true): array
    {
        $rules = ['nullable', $integer ? 'integer' : 'numeric', 'min:0'];

        return [$min => $rules, $max => [...$rules, ...($this->filled($min) ? ['gte:'.$min] : [])]];
    }
}
