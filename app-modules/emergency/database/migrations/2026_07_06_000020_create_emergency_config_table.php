<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table storing emergency (E911) configuration per tenant.
     */
    public function up(): void
    {
        Schema::create('emergency_config', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('caller_id')->nullable();
            $table->string('address');
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->timestamps();

            $table->index(['tenant_id'], 'emergency_config_xml_tenant_idx');
        });
    }

    /**
     * Remove the emergency configuration table.
     */
    public function down(): void
    {
        Schema::dropIfExists('emergency_config');
    }
};
