<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the bridges table for managing call bridge (conference) destinations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bridges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('bridge_name');
            $table->string('destination_number');
            $table->string('pin_number')->nullable();
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled', 'bridge_name'], 'bridges_xml_tenant_enabled_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bridges');
    }
};
