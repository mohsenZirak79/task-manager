<?php

namespace App\Models\Concerns;

use App\Services\RecordNumberService;
use Illuminate\Database\Eloquent\Model;

trait HasRecordNumber
{
    protected static function bootHasRecordNumber(): void
    {
        static::creating(function (Model $model): void {
            [$column, $prefix] = match ($model->getTable()) {
                'tasks' => ['task_number', 'rqst'],
                'reports' => ['report_number', 'rpt'],
                'meetings' => ['meeting_number', 'mtg'],
            };
            $model->{$column} = app(RecordNumberService::class)->next($prefix, $model->created_at ?? now());
        });
        static::updating(function (Model $model): void {
            $column = match ($model->getTable()) {
                'tasks' => 'task_number', 'reports' => 'report_number', 'meetings' => 'meeting_number',
            };
            if ($model->isDirty($column)) {
                throw new \LogicException('شماره رکورد قابل تغییر نیست.');
            }
        });
    }
}
