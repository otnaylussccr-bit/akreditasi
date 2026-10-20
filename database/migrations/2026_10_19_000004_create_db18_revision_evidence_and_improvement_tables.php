<?php

use App\Database\Db18Schema;
use App\Database\Db18SchemaInspector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Db18SchemaInspector::assertCompatibleDatabase();
        Db18SchemaInspector::assertParentSchema();
        Db18SchemaInspector::assertScopeKeysComplete();
        $presentCount = Db18SchemaInspector::assertResumableTablePrefix();

        if ($presentCount < 10) {
            throw new RuntimeException('DB-18 cycle and assessment table prefix is incomplete.');
        }

        for ($index = 10; $index < 20; $index++) {
            if ($index >= $presentCount) {
                DB::unprepared(Db18Schema::statements()[$index]);
                Db18SchemaInspector::assertTableMatches($index);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback blocked: DB-18 revision, evidence, event, and snapshot tables are governed records.');
    }
};
