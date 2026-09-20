<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskPlanningItem extends Model
{
    protected $fillable = [
        'title',
        'weight',
        'progress_percentage',
        'sort_order',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'progress_percentage' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
