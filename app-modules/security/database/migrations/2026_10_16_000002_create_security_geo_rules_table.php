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
     * Creates the dedicated security_geo_rules table.
     * Holds the policy mode (blacklist vs whitelist),
     * the selected ISO country codes, and sync/compilation metrics.
     */
    public function up(): void
    {
        Schema::create('security_geo_rules', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->enum('mode', ['blacklist', 'whitelist'])->default('blacklist');
            $table->string('port_scope')->default('all_traffic');
            $table->json('countries')->nullable();
            $table->enum('sync_interval', ['weekly', 'monthly', 'on_demand'])->default('weekly');
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('v4_cidrs_count')->default(0);
            $table->unsignedInteger('v6_cidrs_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_geo_rules');
    }
};
