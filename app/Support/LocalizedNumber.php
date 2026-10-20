<?php

namespace App\Support;

final class LocalizedNumber
{
    public static function format(float|int $value, int $precision = 0, bool $trimTrailingZeros = false): string
    {
        $decimalSeparator = app()->getLocale() === 'id' ? ',' : '.';
        $thousandsSeparator = app()->getLocale() === 'id' ? '.' : ',';
        $formatted = number_format($value, $precision, $decimalSeparator, $thousandsSeparator);

        if ($trimTrailingZeros && $precision > 0) {
            $formatted = rtrim(rtrim($formatted, '0'), $decimalSeparator);
        }

        return $formatted;
    }
}
