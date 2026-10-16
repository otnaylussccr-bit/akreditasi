<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class BusinessDateTime
{
    public static function inBusinessTimezone(DateTimeInterface|string $value): CarbonImmutable
    {
        $dateTime = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value, config('app.timezone'));

        return $dateTime->setTimezone(config('app.business_timezone'));
    }

    public static function format(DateTimeInterface|string $value, string $format = 'd M Y, H:i'): string
    {
        return self::inBusinessTimezone($value)->format($format);
    }

    public static function label(DateTimeInterface|string $value, string $format = 'd M Y, H:i'): string
    {
        return self::format($value, $format).' WIB';
    }

    public static function startOfDayUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, config('app.business_timezone'))
            ->startOfDay()
            ->setTimezone(config('app.timezone'));
    }

    public static function endOfDayUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, config('app.business_timezone'))
            ->endOfDay()
            ->setTimezone(config('app.timezone'));
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.business_timezone'))->startOfDay();
    }
}
