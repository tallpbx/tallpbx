<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `call_forwards` table for managing per-extension
     * call forwarding settings. Each extension can have at most one
     * forwarding record. Supported forward types: unconditional,
     * busy, no-answer, and not-found.
     */
    public function up(): void
    {
        Schema::create('call_forwards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('extension_uuid')->constrained('extensions')->cascadeOnDelete();
            $table->string('forward_type'); // unconditional, busy, noanswer, notfound
            $table->string('destination');
            $table->integer('ring_timeout')->default(30);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            // One forward type per extension
            $table->unique(['extension_uuid', 'forward_type']);
            $table->index(['tenant_id', 'enabled', 'extension_uuid'], 'call_forwards_xml_tenant_enabled_extension_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_forwards');
    }
};
