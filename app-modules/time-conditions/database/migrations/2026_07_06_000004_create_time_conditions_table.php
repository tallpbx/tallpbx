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
     * Creates the `time_conditions` table for storing time-based
     * call routing rules. Each condition defines a time window
     * (weekdays and hours) and destinations for matched vs
     * unmatched calls.
     */
    public function up(): void
    {
        Schema::create('time_conditions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('timezone')->nullable();
            $table->string('weekdays')->default('mon,tue,wed,thu,fri');
            $table->string('start_time')->default('09:00');
            $table->string('end_time')->default('17:00');
            $table->string('destination_on_match')->nullable();
            $table->string('destination_on_no_match')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled', 'name'], 'time_conditions_xml_tenant_enabled_name_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the time_conditions table.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_conditions');
    }
};
