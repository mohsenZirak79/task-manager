<?php

namespace App\Services;

use App\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardScheduleStatistics
{
    public function build(Builder $assignments, CarbonImmutable $now): array
    {
        [$deadline, $deadlineBindings] = $this->deadlineSql($assignments);
        $source = (clone $assignments)->select(['status', 'due_date', 'completed_at', 'completion_requested_at'])
            ->selectRaw("$deadline AS deadline", $deadlineBindings);
        $excluded = TaskStatus::dashboardValues('schedule', 'excluded');
        $calculable = [...TaskStatus::dashboardValues('schedule', 'open'), TaskStatus::Completed->value];
        $placeholders = fn (array $values) => implode(',', array_fill(0, count($values), '?'));
        $bucket = "CASE
            WHEN status IN ({$placeholders($excluded)}) THEN 'excluded_status'
            WHEN status NOT IN ({$placeholders($calculable)}) THEN 'unsupported_status'
            WHEN due_date IS NULL THEN 'missing_due_date'
            WHEN status = ? AND COALESCE(completion_requested_at, completed_at) IS NULL THEN 'missing_completed_at'
            WHEN status = ? THEN CASE WHEN COALESCE(completion_requested_at, completed_at) <= deadline THEN 'on_schedule' ELSE 'overdue' END
            WHEN deadline < ? THEN 'overdue'
            WHEN deadline <= ? THEN 'critical'
            ELSE 'on_schedule' END";
        $classified = DB::query()->fromSub($source->toBase(), 'schedule_source')->selectRaw("$bucket AS bucket", [
            ...$excluded, ...$calculable, TaskStatus::Completed->value, TaskStatus::Completed->value,
            $now->utc()->format('Y-m-d H:i:s.u'), $now->utc()->addHours(48)->format('Y-m-d H:i:s.u'),
        ])->selectRaw('CASE WHEN status = ? AND due_date IS NOT NULL AND completion_requested_at IS NULL AND completed_at IS NOT NULL THEN 1 ELSE 0 END AS used_fallback', [TaskStatus::Completed->value]);
        $groups = DB::query()->fromSub($classified, 'schedule_buckets')
            ->selectRaw('bucket, COUNT(*) AS aggregate, SUM(used_fallback) AS fallback_count')->groupBy('bucket')->get();
        $counts = $groups->pluck('aggregate', 'bucket')->all();
        $excludedCounts = [];
        foreach (['excluded_status', 'missing_due_date', 'missing_completed_at'] as $key) {
            $excludedCounts[$key] = (int) ($counts[$key] ?? 0);
        }
        // Unknown persisted values are never silently treated as open.
        if (isset($counts['unsupported_status'])) {
            $excludedCounts['unsupported_status'] = (int) $counts['unsupported_status'];
        }
        $total = (int) array_sum($counts) - array_sum($excludedCounts);
        $items = [];
        foreach (['on_schedule' => 'طبق برنامه', 'critical' => 'بحرانی', 'overdue' => 'عقب‌افتاده'] as $key => $label) {
            $count = (int) ($counts[$key] ?? 0);
            $items[] = ['key' => $key, 'label' => $label, 'count' => $count,
                'percentage' => $total > 0 ? round($count * 100 / $total, 2, PHP_ROUND_HALF_UP) : 0.0];
        }

        return [
            'available' => true, 'examined_total' => (int) array_sum($counts), 'total' => $total,
            'excluded_total' => array_sum($excludedCounts), 'excluded_counts' => $excludedCounts,
            'fallback_completed_at_count' => (int) $groups->sum('fallback_count'), 'items' => $items,
        ];
    }

    /** UTC deadline at the end of the Tehran date; no dependency on DB timezone tables.
     * Only date bounds are loaded. IANA transitions handle historical Tehran DST too.
     */
    private function deadlineSql(Builder $assignments): array
    {
        $bounds = (clone $assignments)->selectRaw('MIN(due_date) AS first_date, MAX(due_date) AS last_date')->toBase()->first();
        $first = CarbonImmutable::parse($bounds->first_date ?? '2000-01-01', 'Asia/Tehran')->startOfDay();
        $last = CarbonImmutable::parse($bounds->last_date ?? '2000-01-01', 'Asia/Tehran')->endOfDay();
        $offsets = [];
        foreach ((new DateTimeZone('Asia/Tehran'))->getTransitions($first->timestamp, $last->timestamp + 1) as $transition) {
            $day = CarbonImmutable::createFromTimestamp($transition['ts'], 'Asia/Tehran');
            $offsets[$day->toDateString()] = $day->endOfDay()->offset;
        }
        krsort($offsets);
        $offset = 'CASE';
        $bindings = [];
        foreach ($offsets as $date => $seconds) {
            $offset .= ' WHEN due_date >= ? THEN (? + 0)';
            array_push($bindings, $date, (int) $seconds);
        }
        $offset .= ' ELSE (? + 0) END';
        $bindings[] = (int) $first->endOfDay()->offset;

        $sql = match ($assignments->getConnection()->getDriverName()) {
            'sqlite' => "datetime(date(due_date) || ' 23:59:59', printf('%+d seconds', -($offset))) || '.999999'",
            'mysql', 'mariadb' => "TIMESTAMPADD(SECOND, -($offset), TIMESTAMP(due_date, '23:59:59.999999'))",
            'pgsql' => "(due_date::timestamp + INTERVAL '23 hours 59 minutes 59.999999 seconds' - ($offset) * INTERVAL '1 second')",
            default => throw new \LogicException('Dashboard deadline SQL is unsupported for this database driver.'),
        };

        return [$sql, $bindings];
    }
}
