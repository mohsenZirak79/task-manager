<?php

namespace App\Http\Requests\Concerns;

use App\Models\Meeting;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait HasMeetingPayloadRules
{
    /** @return array<string, array<int, mixed>> */
    protected function meetingPayloadRules(bool $updating = false): array
    {
        $userExists = Rule::exists('users', 'id')->whereNull('deleted_at')->where('is_active', true);

        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'location' => ['sometimes', 'nullable', 'string', 'max:500'],
            'meeting_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'chairman_user_id' => ['sometimes', 'nullable', 'integer', $userExists],
            'secretary_user_id' => ['sometimes', 'nullable', 'integer', $userExists],
            'attendee_user_ids' => ['sometimes', 'array'],
            'attendee_user_ids.*' => ['integer', 'distinct', $userExists],
            'agenda_items' => ['sometimes', 'array'],
            'agenda_items.*.id' => $updating
                ? ['sometimes', 'integer', 'distinct', Rule::exists('meeting_agenda_items', 'id')]
                : ['prohibited'],
            'agenda_items.*.title' => ['required', 'string', 'max:255'],
            'agenda_items.*.description' => ['sometimes', 'nullable', 'string'],
            'agenda_items.*.sort_order' => ['required', 'integer', 'min:0', 'distinct'],
            'submit' => ['sometimes', 'boolean'],
        ];
    }

    protected function addMeetingSubmitErrors(Validator $validator, ?Meeting $meeting = null): void
    {
        if (! $this->boolean('submit')) {
            return;
        }

        $required = [
            'title' => 'عنوان جلسه هنگام ارسال اجباری است.',
            'location' => 'مکان جلسه هنگام ارسال اجباری است.',
            'meeting_date' => 'تاریخ جلسه هنگام ارسال اجباری است.',
            'start_time' => 'ساعت جلسه هنگام ارسال اجباری است.',
            'chairman_user_id' => 'رئیس جلسه هنگام ارسال اجباری است.',
            'secretary_user_id' => 'دبیر جلسه هنگام ارسال اجباری است.',
        ];

        foreach ($required as $field => $message) {
            $value = array_key_exists($field, $this->all()) ? $this->input($field) : $meeting?->{$field};
            if ($value === null || $value === '') {
                $validator->errors()->add($field, $message);
            }
        }

        $hasAgenda = array_key_exists('agenda_items', $this->all())
            ? count((array) $this->input('agenda_items', [])) > 0
            : ($meeting?->agendaItems()->exists() ?? false);
        if (! $hasAgenda) {
            $validator->errors()->add('agenda_items', 'حداقل یک دستور جلسه هنگام ارسال اجباری است.');
        }
    }

    public function messages(): array
    {
        return [
            'meeting_date.date_format' => 'تاریخ جلسه باید میلادی و با قالب YYYY-MM-DD باشد.',
            'start_time.date_format' => 'ساعت جلسه باید با قالب HH:MM باشد.',
            '*.exists' => 'شناسه انتخاب‌شده معتبر یا فعال نیست.',
            'attendee_user_ids.*.distinct' => 'شناسه حاضرین نباید تکراری باشد.',
            'agenda_items.*.sort_order.distinct' => 'ترتیب دستورهای جلسه نباید تکراری باشد.',
            'agenda_items.*.title.required' => 'عنوان هر دستور جلسه اجباری است.',
        ];
    }
}
