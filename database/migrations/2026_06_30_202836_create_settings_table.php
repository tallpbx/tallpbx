<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the settings table for system-wide and tenant-specific defaults.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string, integer, boolean, json
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['key', 'tenant_id']);
        });
    }

    /**
     * Reverse the database migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
