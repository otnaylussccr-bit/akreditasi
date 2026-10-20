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

        if (Db18SchemaInspector::assertResumableTablePrefix() !== 20) {
            throw new RuntimeException('DB-18 table prefix must be complete before circular pointer foreign keys are added.');
        }

        $pointers = Db18SchemaInspector::circularPointerForeignKeys();

        if ($pointers === []) {
            DB::unprepared(Db18Schema::statements()[20]);
        } elseif ($pointers !== ['fk_led_approved_ptr', 'fk_led_working_ptr']) {
            throw new RuntimeException('DB-18 circular pointer foreign keys are in a partial state. Inspect before retrying.');
        }

        Db18SchemaInspector::assertCircularPointersMatch();
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback blocked: DB-18 revision pointers protect approved and working assessment provenance.');
    }
};
