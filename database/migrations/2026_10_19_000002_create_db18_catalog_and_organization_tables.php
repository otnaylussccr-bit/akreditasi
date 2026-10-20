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

        for ($index = 0; $index < 5; $index++) {
            if ($index >= $presentCount) {
                DB::unprepared(Db18Schema::statements()[$index]);
                Db18SchemaInspector::assertTableMatches($index);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback blocked: DB-18 organization and instrument tables may contain governed records.');
    }
};
