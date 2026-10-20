<?php

use App\Database\Db18SchemaInspector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SCOPE_KEYS = [
        'uq_led_project_instrument' => 'ALTER TABLE `accreditation_projects` ADD UNIQUE KEY `uq_led_project_instrument` (`id`,`instrument_id`)',
        'uq_led_criterion_project' => 'ALTER TABLE `accreditation_criteria` ADD UNIQUE KEY `uq_led_criterion_project` (`id`,`project_id`)',
        'uq_led_item_criterion' => 'ALTER TABLE `accreditation_items` ADD UNIQUE KEY `uq_led_item_criterion` (`id`,`criterion_id`)',
    ];

    public function up(): void
    {
        Db18SchemaInspector::assertCompatibleDatabase();
        Db18SchemaInspector::assertParentSchema();
        Db18SchemaInspector::assertResumableTablePrefix();
        $scopeKeys = Db18SchemaInspector::scopeKeys();

        foreach (self::SCOPE_KEYS as $name => $statement) {
            if (! $scopeKeys[$name]) {
                DB::statement($statement);
            }
        }

        if (in_array(false, Db18SchemaInspector::scopeKeys(), true)) {
            throw new RuntimeException('DB-18 scope-key recovery did not complete.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback blocked: DB-18 Phase 1 scope keys may protect governed relationships.');
    }
};
