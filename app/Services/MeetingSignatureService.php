<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingSignature;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MeetingSignatureService
{
    public function __construct(private readonly MeetingSignatureContent $content) {}

    public function sign(Meeting $meeting, User $actor, string $expectedVersion): Meeting
    {
        return DB::transaction(function () use ($meeting, $actor, $expectedVersion): Meeting {
            // Same parent lock used by meeting and resolution mutations.
            $meeting = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            Gate::forUser($actor)->authorize('sign', $meeting);
            $snapshot = $this->content->snapshot($meeting);
            $version = $this->content->version($snapshot);
            if (! hash_equals($version, $expectedVersion)) {
                throw new ConflictHttpException('محتوای صورت‌جلسه تغییر کرده است؛ نسخه جدید را مشاهده و دوباره تأیید کنید.');
            }

            MeetingSignature::query()->firstOrCreate([
                'meeting_id' => $meeting->id,
                'user_id' => $actor->id,
                'content_version' => $version,
            ], [
                'content_snapshot' => $snapshot,
                'signed_at' => now(),
            ]);

            return app(MeetingService::class)->find($meeting);
        });
    }
}
