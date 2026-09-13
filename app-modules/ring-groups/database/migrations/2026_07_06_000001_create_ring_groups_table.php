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
     * Creates the `ring_groups` table for storing ring group configurations
     * and the `ring_group_extensions` table for the extensions assigned to
     * each group with their ring order position.
     */
    public function up(): void
    {
        Schema::create('ring_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('strategy'); // ring-all, sequential, round-robin
            $table->integer('ring_timeout')->default(30);
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled', 'name'], 'ring_groups_xml_tenant_enabled_name_idx');
        });

        Schema::create('ring_group_extensions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ring_group_id')->constrained('ring_groups')->cascadeOnDelete();
            $table->string('extension_uuid');
            $table->integer('position');
            $table->timestamps();

            $table->unique(['ring_group_id', 'position']);
            $table->index(['ring_group_id', 'position'], 'ring_group_extensions_xml_group_position_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the ring_group_extensions table first to respect
     * the foreign key constraint, then drops ring_groups.
     */
    public function down(): void
    {
        Schema::dropIfExists('ring_group_extensions');
        Schema::dropIfExists('ring_groups');
    }
};
