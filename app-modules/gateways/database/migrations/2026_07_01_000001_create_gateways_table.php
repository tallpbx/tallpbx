<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gateways represent SIP trunks and upstream providers that the PBX
     * routes outbound calls through. Each gateway stores connection details
     * (host, port, credentials) and optionally registers with the provider.
     */
    public function up(): void
    {
        Schema::create('gateways', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('host');
            $table->integer('port')->default(5060);
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('realm')->nullable();
            $table->string('proxy')->nullable();
            $table->boolean('register')->default(true);
            $table->string('context')->default('public');
            $table->string('profile')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'profile', 'enabled'], 'gateways_tenant_profile_enabled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateways');
    }
};
