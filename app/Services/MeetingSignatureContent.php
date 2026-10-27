<?php

namespace App\Services;

use App\Models\Meeting;

/** Content-addressed versions of the minutes, independent of live task/report progress. */
class MeetingSignatureContent
{
    public function snapshot(Meeting $meeting): array
    {
        $meeting->loadMissing(['attendees', 'agendaItems', 'resolutions.report']);

        return [
            'schema_version' => 1,
            'meeting' => [
                'id' => (int) $meeting->id,
                'title' => $meeting->title,
                'short_description' => $meeting->short_description,
                'location' => $meeting->location,
                'meeting_date' => $meeting->meeting_date?->toDateString(),
                'start_time' => $meeting->start_time?->format('H:i:s'),
                'chairman_user_id' => $meeting->chairman_user_id === null ? null : (int) $meeting->chairman_user_id,
                'secretary_user_id' => $meeting->secretary_user_id === null ? null : (int) $meeting->secretary_user_id,
            ],
            'attendee_user_ids' => $meeting->attendees->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            'agenda_items' => $meeting->agendaItems->sortBy([['sort_order', 'asc'], ['id', 'asc']])->map(fn ($item) => [
                'id' => (int) $item->id, 'title' => $item->title, 'description' => $item->description,
                'sort_order' => (int) $item->sort_order,
            ])->values()->all(),
            'resolutions' => $meeting->resolutions->sortBy([['sort_order', 'asc'], ['id', 'asc']])->map(fn ($item) => [
                'id' => (int) $item->id,
                'agenda_item_id' => $item->agenda_item_id === null ? null : (int) $item->agenda_item_id,
                'title' => $item->title, 'description' => $item->description,
                'resolution_type' => $item->resolution_type->value,
                'task_id' => $item->task_id === null ? null : (int) $item->task_id,
                'report_id' => $item->report?->id,
                'sort_order' => (int) $item->sort_order,
            ])->values()->all(),
        ];
    }

    public function version(array $snapshot): string
    {
        return hash('sha256', json_encode($this->canonicalize($snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function canonicalize(array $value): array
    {
        // MySQL JSON may reorder object keys; archived snapshots must reproduce the same hash.
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
