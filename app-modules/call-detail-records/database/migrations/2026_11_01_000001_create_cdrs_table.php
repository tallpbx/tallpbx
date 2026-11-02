<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table that stores one row per FreeSWITCH call leg.
     */
    public function up(): void
    {
        Schema::create('cdrs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('call_uuid')->nullable()->index();

            // The B-leg records which A-leg bridged it, so both halves of a
            // call can be shown together.
            $table->string('b_leg_uuid')->nullable()->index();

            // 'a' or 'b'. The pair (call_uuid, leg) makes repeated ingestion
            // of the same payload an update instead of a duplicate row.
            $table->char('leg', 1)->default('a')->index();

            $table->string('caller_id')->nullable();
            $table->string('caller_id_name')->nullable();
            $table->string('destination')->nullable();
            $table->unsignedInteger('duration')->default(0);
            $table->unsignedInteger('billsec')->default(0);
            $table->string('hangup_cause')->nullable();
            $table->string('direction')->nullable();

            // Where FreeSWITCH wrote the call recording, when one exists.
            $table->string('record_path')->nullable();

            $table->timestamp('start_stamp')->nullable();
            $table->timestamp('answer_stamp')->nullable();
            $table->timestamp('end_stamp')->nullable();

            // The untouched JSON payload, kept for diagnostics and replay.
            $table->longText('json_data')->nullable();

            $table->timestamps();

            $table->unique(['call_uuid', 'leg'], 'cdrs_call_uuid_leg_unique');

            // The panel lists a tenant's records newest first; this composite
            // index serves that ordering without a filesort.
            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Remove the call detail records table.
     */
    public function down(): void
    {
        Schema::dropIfExists('cdrs');
    }
};
