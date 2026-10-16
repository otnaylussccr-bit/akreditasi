<?php

namespace Tests\Unit;

use App\Support\BusinessDateTime;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class BusinessDateTimeTest extends TestCase
{
    public function test_utc_instants_are_formatted_in_wib_deterministically(): void
    {
        $this->assertSame(
            '16 Oct 2026, 16:00 WIB',
            BusinessDateTime::label(CarbonImmutable::parse('2026-10-16 09:00:00', 'UTC')),
        );
        $this->assertSame(
            '17 Oct 2026, 06:30 WIB',
            BusinessDateTime::label(CarbonImmutable::parse('2026-10-16 23:30:00', 'UTC')),
        );
    }

    public function test_an_instant_already_in_wib_is_not_converted_twice(): void
    {
        $wib = CarbonImmutable::parse('2026-10-16 16:00:00', 'Asia/Jakarta');

        $this->assertSame('16 Oct 2026, 16:00 WIB', BusinessDateTime::label($wib));
        $this->assertSame($wib->timestamp, BusinessDateTime::inBusinessTimezone($wib)->timestamp);
    }

    public function test_wib_calendar_day_boundaries_are_converted_to_utc_for_queries(): void
    {
        $this->assertSame('2026-10-15 17:00:00 +00:00', BusinessDateTime::startOfDayUtc('2026-10-16')->format('Y-m-d H:i:s P'));
        $this->assertSame('2026-10-16 16:59:59 +00:00', BusinessDateTime::endOfDayUtc('2026-10-16')->format('Y-m-d H:i:s P'));
    }

    public function test_application_and_database_timezone_contract_is_explicit(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Asia/Jakarta', config('app.business_timezone'));
        $this->assertSame('+00:00', config('database.connections.mysql.timezone'));
        $this->assertSame('+00:00', config('database.connections.mariadb.timezone'));
    }
}
