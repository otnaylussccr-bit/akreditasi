<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MariaDbTimezoneContractTest extends TestCase
{
    public function test_mariadb_session_is_utc_and_timestamp_round_trip_preserves_the_instant(): void
    {
        if (! filter_var(env('RUN_MARIADB_TIMEZONE_TESTS', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set RUN_MARIADB_TIMEZONE_TESTS=1 to run against local MariaDB.');
        }

        $database = env('MARIADB_TEST_DATABASE');

        if (! is_string($database) || $database === '') {
            $this->markTestSkipped('Set MARIADB_TEST_DATABASE to the isolated local database name.');
        }

        config(['database.connections.mariadb.database' => $database]);
        $connection = DB::connection('mariadb');
        $session = $connection->selectOne('SELECT @@session.time_zone AS timezone');

        $this->assertSame('+00:00', $session->timezone);

        $connection->statement('DROP TEMPORARY TABLE IF EXISTS ams_timezone_contract_test');
        $connection->statement('CREATE TEMPORARY TABLE ams_timezone_contract_test (recorded_at TIMESTAMP NOT NULL)');

        try {
            $connection->insert(
                'INSERT INTO ams_timezone_contract_test (recorded_at) VALUES (?)',
                ['2026-10-16 09:00:00'],
            );
            $stored = $connection->selectOne(
                'SELECT recorded_at, UNIX_TIMESTAMP(recorded_at) AS recorded_epoch FROM ams_timezone_contract_test',
            );

            $this->assertSame('2026-10-16 09:00:00', $stored->recorded_at);
            $this->assertSame(
                CarbonImmutable::parse('2026-10-16 09:00:00', 'UTC')->timestamp,
                (int) $stored->recorded_epoch,
            );
        } finally {
            $connection->statement('DROP TEMPORARY TABLE IF EXISTS ams_timezone_contract_test');
            DB::purge('mariadb');
        }
    }
}
