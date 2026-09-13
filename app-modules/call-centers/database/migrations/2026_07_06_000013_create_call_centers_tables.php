<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_center_queues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('strategy')->default('ring-all');
            $table->unsignedInteger('timeout')->default(30);
            $table->string('music_on_hold')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled', 'name'], 'call_center_queues_xml_tenant_enabled_name_idx');
        });
        Schema::create('call_center_agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('callback');
            $table->string('destination');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        Schema::create('call_center_tiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('queue_id')->constrained('call_center_queues')->cascadeOnDelete();
            $table->foreignUuid('agent_id')->constrained('call_center_agents')->cascadeOnDelete();
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_center_tiers');
        Schema::dropIfExists('call_center_agents');
        Schema::dropIfExists('call_center_queues');
    }
};
