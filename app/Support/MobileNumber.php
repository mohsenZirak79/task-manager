<?php

namespace App\Support;

class MobileNumber
{
    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const ARABIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private const LATIN_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    public static function normalize(?string $value): string
    {
        $value = str_replace(self::PERSIAN_DIGITS, self::LATIN_DIGITS, trim((string) $value));
        $value = str_replace(self::ARABIC_DIGITS, self::LATIN_DIGITS, $value);
        $value = preg_replace('/[\s\-()]/u', '', $value) ?? $value;

        if (str_starts_with($value, '0098')) {
            return '0'.substr($value, 4);
        }

        if (str_starts_with($value, '+98')) {
            return '0'.substr($value, 3);
        }

        if (str_starts_with($value, '98') && strlen($value) === 12) {
            return '0'.substr($value, 2);
        }

        return $value;
    }

    /** @return list<string> */
    public static function variants(string $value): array
    {
        $normalized = self::normalize($value);

        if (! preg_match('/^09\d{9}$/', $normalized)) {
            return [$normalized];
        }

        $withoutLeadingZero = substr($normalized, 1);
        $persian = str_replace(self::LATIN_DIGITS, self::PERSIAN_DIGITS, $normalized);

        return array_values(array_unique([
            $normalized,
            '+98'.$withoutLeadingZero,
            '98'.$withoutLeadingZero,
            '0098'.$withoutLeadingZero,
            $persian,
        ]));
    }
}
