<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthOtp extends Model
{
    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_FORGOT_PASSWORD = 'forgot_password';
    public const PURPOSE_SET_PASSWORD = 'set_password';

    public const PURPOSES = [
        self::PURPOSE_LOGIN,
        self::PURPOSE_FORGOT_PASSWORD,
        self::PURPOSE_SET_PASSWORD,
    ];

    protected $fillable = [
        'user_id',
        'identifier',
        'purpose',
        'code_hash',
        'expires_at',
        'used_at',
        'attempts',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
