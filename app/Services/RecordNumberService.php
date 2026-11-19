<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class RecordNumberService
{
    public function dateKey(DateTimeInterface|string $date): string
    {
        $date = CarbonImmutable::parse($date, config('app.timezone'))->setTimezone('Asia/Tehran');
        $gy = $date->year;
        $gm = $date->month;
        $gd = $date->day;
        $offsets = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $jy = $gy > 1600 ? 979 : 0;
        $gy -= $gy > 1600 ? 1600 : 621;
        $adjusted = $gm > 2 ? $gy + 1 : $gy;
        $days = 365 * $gy + intdiv($adjusted + 3, 4) - intdiv($adjusted + 99, 100)
            + intdiv($adjusted + 399, 400) - 80 + $gd + $offsets[$gm - 1];
        $jy += 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $jm = $days < 186 ? 1 + intdiv($days, 31) : 7 + intdiv($days - 186, 30);
        $jd = 1 + ($days < 186 ? $days % 31 : ($days - 186) % 30);

        return sprintf('%02d%02d%02d', $jy % 100, $jm, $jd);
    }

    public function next(string $prefix, DateTimeInterface|string $date): string
    {
        $key = $prefix.$this->dateKey($date);

        return DB::transaction(function () use ($key): string {
            DB::table('record_number_counters')->insertOrIgnore(['key' => $key, 'sequence' => 0]);
            $counter = DB::table('record_number_counters')->where('key', $key)->lockForUpdate()->first();
            $sequence = $counter->sequence + 1;
            DB::table('record_number_counters')->where('key', $key)->update(['sequence' => $sequence]);

            return $key.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
        }, 5);
    }
}
