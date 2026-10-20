<?php

namespace App\Console\Commands;

use App\Database\Db18Schema;
use App\Database\Db18SchemaInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class InspectDb18Migration extends Command
{
    protected $signature = 'db18:inspect {--database= : The configured database connection to inspect}';

    protected $description = 'Read-only inspection of DB-18 migration and partial-DDL state';

    public function handle(): int
    {
        try {
            $connection = $this->option('database');
            $report = is_string($connection) && $connection !== ''
                ? DB::usingConnection($connection, fn (): array => Db18SchemaInspector::report())
                : Db18SchemaInspector::report();
        } catch (Throwable $exception) {
            $this->error('DB18_STATUS=INVALID');
            $this->error('DB18_REASON='.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line('DB18_SHA256='.Db18Schema::SHA256);
        $this->line('DB18_DATABASE='.$report['database']);
        $this->line('DB18_SERVER='.$report['server_version']);
        $this->line('DB18_STATUS='.$report['status']);
        $this->line('DB18_TABLES='.count($report['present_tables']).'/20');
        $this->line('DB18_SCOPE_KEYS='.count(array_filter($report['scope_keys'])).'/3');
        $this->line('DB18_POINTER_FKS='.count($report['pointer_foreign_keys']).'/2');
        $this->line('DB18_MIGRATIONS='.count($report['recorded_migrations']).'/5');
        $this->warn('Read-only inspection only. No table, constraint, migration row, or application data was changed.');

        return in_array($report['status'], ['PRISTINE', 'PARTIAL_SCOPE_KEYS', 'SCOPE_KEYS_ONLY', 'PARTIAL_TABLE_PREFIX', 'TABLES_COMPLETE_POINTERS_PENDING', 'COMPLETE'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }
}
