<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * IMPORTANT: The actual review-event table must be verified before running.
     * This migration deliberately fails closed rather than silently creating
     * attachments without a valid review-event relationship.
     */
    public function up(): void
    {
        if (Schema::hasTable('accreditation_review_attachments')) {
            throw new RuntimeException('Table accreditation_review_attachments already exists. Inspect its schema; no changes were made.');
        }

        $eventTable = 'accreditation_review_events';

        if (! Schema::hasTable($eventTable)) {
            throw new RuntimeException(
                "PRECHECK FAILED: expected parent table '{$eventTable}' does not exist. "
                .'Find the actual review-event table/model and adapt the foreign key before running this migration.'
            );
        }

        $eventId = DB::selectOne(
            'SELECT DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$eventTable, 'id']
        );

        if (! $eventId || $eventId->DATA_TYPE !== 'bigint' || stripos($eventId->COLUMN_TYPE, 'unsigned') === false) {
            throw new RuntimeException(
                "PRECHECK FAILED: {$eventTable}.id is not BIGINT UNSIGNED. "
                .'Foreign-key type must match the actual parent primary key; no changes were made.'
            );
        }

        Schema::create('accreditation_review_attachments', function (Blueprint $table) use ($eventTable) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('review_event_id');
            $table->uuid('storage_uuid')->unique();
            $table->string('storage_path', 1024);
            $table->string('original_filename', 255);
            $table->string('mime_type', 191);
            $table->string('extension', 20);
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64);
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamps();

            $table->index(['review_event_id', 'uploaded_at'], 'review_attach_event_uploaded_idx');
            $table->foreign('review_event_id', 'review_attach_event_fk')
                ->references('id')->on($eventTable)
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Intentionally no automatic DROP for immutable audit evidence.
        throw new RuntimeException(
            'Rollback blocked: accreditation_review_attachments contains potential audit evidence. '
            .'Review data and approve an explicit manual rollback plan.'
        );
    }
};
