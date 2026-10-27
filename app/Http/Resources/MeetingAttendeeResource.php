<?php

namespace App\Http\Resources;

use App\Models\MeetingSignature;
use Illuminate\Http\Request;

class MeetingAttendeeResource extends UserSummaryResource
{
    public function __construct($resource, private readonly ?MeetingSignature $signature, private readonly string $version, private readonly bool $canSign)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $signed = $this->signature?->content_version === $this->version;

        return [
            ...parent::toArray($request),
            'signature_status' => $signed ? 'signed' : ($this->signature ? 'outdated' : 'unsigned'),
            'signed_at' => $signed ? $this->signature->signed_at->toISOString() : null,
            'signed_content_version' => $this->signature?->content_version,
            'can_sign' => $this->canSign && ! $signed,
        ];
    }
}
