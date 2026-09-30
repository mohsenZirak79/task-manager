<?php

namespace App\Services;

use App\Enums\MeetingResolutionType;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingResolution;
use App\Models\Report;
use App\Models\Tag;
use App\Models\User;
use App\Support\AccessRoles;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ReportService
{
    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        $query = Report::query()
            ->with($this->relations())
            ->latest('reports.id');

        if (! $actor->isSuperAdmin() && ! $actor->hasAccessRole(AccessRoles::ADMIN)) {
            $query->where(function ($query) use ($actor): void {
                $query->where('reports.created_by', $actor->id)
                    ->orWhere('recipient_user_id', $actor->id)
                    ->orWhereHas('ccUsers', fn ($query) => $query->whereKey($actor->id))
                    ->orWhereHas('resolution.meeting', function ($query) use ($actor): void {
                        $query->where('created_by', $actor->id)
                            ->orWhere('chairman_user_id', $actor->id)
                            ->orWhere('secretary_user_id', $actor->id)
                            ->orWhereHas('attendees', fn ($query) => $query->whereKey($actor->id));
                    });
            });
        }

        $query->when($filters['search'] ?? null, function ($query, string $search): void {
            $query->where(function ($query) use ($search): void {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('resolution', fn ($query) => $query->where('title', 'like', "%{$search}%"));
            });
        });

        $query->when($filters['meeting_id'] ?? null, fn ($query, $id) => $query
            ->whereHas('resolution', fn ($query) => $query->where('meeting_id', $id)));
        $query->when($filters['resolution_id'] ?? null, fn ($query, $id) => $query->where('meeting_resolution_id', $id));
        $query->when($filters['recipient_user_id'] ?? null, fn ($query, $id) => $query->where('recipient_user_id', $id));
        $query->when($filters['tag_id'] ?? null, fn ($query, $id) => $query
            ->whereHas('tags', fn ($query) => $query->whereKey($id)));

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function find(Report $report): Report
    {
        return $report->load($this->relations());
    }

    public function create(Meeting $meeting, MeetingResolution $resolution, array $data, User $actor): Report
    {
        return DB::transaction(function () use ($meeting, $resolution, $data, $actor): Report {
            $resolution = MeetingResolution::query()->lockForUpdate()->findOrFail($resolution->id);
            $this->ensureResolution($meeting, $resolution);
            $this->ensureMeetingEditable($meeting);

            if ($resolution->resolution_type !== MeetingResolutionType::Report) {
                throw new ConflictHttpException('فقط برای مصوبه‌ای با نوع گزارش می‌توان گزارش ثبت کرد.');
            }
            if ($resolution->task_id !== null) {
                throw new ConflictHttpException('این مصوبه قبلاً به یک تسک متصل شده است.');
            }
            if ($resolution->report()->exists()) {
                throw new ConflictHttpException('برای این مصوبه قبلاً گزارش ثبت شده است.');
            }

            $report = $resolution->report()->create([
                ...Arr::only($data, ['title', 'short_description', 'description', 'recipient_user_id']),
                'created_by' => $actor->id,
            ]);
            $this->syncRelations($report, $data, true);

            return $this->find($report);
        });
    }

    public function update(Report $report, array $data): Report
    {
        return DB::transaction(function () use ($report, $data): Report {
            $report = Report::query()->lockForUpdate()->findOrFail($report->id);
            $report->load('resolution.meeting');
            $this->ensureMeetingEditable($report->resolution->meeting);
            $report->update(Arr::only($data, ['title', 'short_description', 'description', 'recipient_user_id']));
            $this->syncRelations($report, $data);

            return $this->find($report);
        });
    }

    public function delete(Report $report): void
    {
        DB::transaction(function () use ($report): void {
            $report = Report::query()->lockForUpdate()->findOrFail($report->id);
            $report->load('resolution.meeting');
            $this->ensureMeetingEditable($report->resolution->meeting);
            $report->delete();
        });
    }

    public function eligibleUsers(array $filters): LengthAwarePaginator
    {
        return User::query()
            ->where('is_active', true)
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('org_code', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%");
                });
            })
            ->orderBy('first_name')->orderBy('last_name')->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);
    }

    private function syncRelations(Report $report, array $data, bool $creating = false): void
    {
        if ($creating || array_key_exists('cc_user_ids', $data)) {
            $report->ccUsers()->sync(array_values(array_unique(array_map('intval', $data['cc_user_ids'] ?? []))));
        }
        if (! $creating && ! array_key_exists('tags', $data)) {
            return;
        }

        $tagIds = collect($data['tags'] ?? [])
            ->map(fn (string $title): string => trim($title))
            ->filter()
            ->unique(fn (string $title): string => mb_strtolower($title))
            ->map(function (string $title): int {
                $tag = Tag::query()->whereRaw('LOWER(title) = ?', [mb_strtolower($title)])->first();

                return ($tag ?? Tag::query()->firstOrCreate(['title' => $title]))->id;
            })->all();
        $report->tags()->sync($tagIds);
    }

    private function ensureResolution(Meeting $meeting, MeetingResolution $resolution): void
    {
        if ($resolution->meeting_id !== $meeting->id) {
            abort(404);
        }
    }

    private function ensureMeetingEditable(Meeting $meeting): void
    {
        if ($meeting->status !== MeetingStatus::Scheduled) {
            throw new ConflictHttpException('مدیریت گزارش فقط برای جلسه زمان‌بندی‌شده مجاز است.');
        }
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['resolution.meeting', 'resolution.agendaItem', 'recipient', 'ccUsers', 'tags', 'creator'];
    }
}
