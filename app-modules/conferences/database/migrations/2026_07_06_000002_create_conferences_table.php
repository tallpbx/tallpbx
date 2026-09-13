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
     * Creates the `conferences` table for storing conference room
     * configurations including the FreeSWITCH profile to use,
     * an optional PIN for caller authentication, and a maximum
     * member limit.
     */
    public function up(): void
    {
        Schema::create('conferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('profile')->default('sample');
            $table->string('pin')->nullable();
            $table->integer('max_members')->default(100);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled', 'name'], 'conferences_xml_tenant_enabled_name_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the conferences table.
     */
    public function down(): void
    {
        Schema::dropIfExists('conferences');
    }
};
