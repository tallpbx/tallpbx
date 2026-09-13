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
     * Creates the `conference_centers` table for storing conference
     * center dial-in configurations. Each center provides a dial-in
     * extension, optional PIN, and optional greeting audio file.
     */
    public function up(): void
    {
        Schema::create('conference_centers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('extension');
            $table->string('pin')->nullable();
            $table->string('greeting')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled', 'name'], 'conference_centers_xml_tenant_enabled_name_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the conference_centers table.
     */
    public function down(): void
    {
        Schema::dropIfExists('conference_centers');
    }
};
