<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class ProjectTagSeeder extends Seeder
{
    public const TITLES = [
        'پروژه آسیما',
        'پروژه فیکوشاپ',
        'پروژه تسک منیجر',
        'بازرگانی',
        'فنی',
        'مالی',
        'فروش',
        'پشتیبانی',
        'عملیات',
        'منابع انسانی',
        'بازاریابی',
        'مدیریت',
    ];

    public function run(): void
    {
        foreach (self::TITLES as $title) {
            Tag::query()->firstOrCreate(['title' => $title]);
        }
    }
}
