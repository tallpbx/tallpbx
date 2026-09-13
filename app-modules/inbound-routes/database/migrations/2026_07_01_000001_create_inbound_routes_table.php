<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('destination_number');
            $table->string('action');
            $table->string('action_data')->nullable();
            $table->integer('priority')->default(100);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'destination_number']);
            $table->index(['tenant_id', 'priority']);
            $table->index(['tenant_id', 'enabled', 'priority'], 'inbound_routes_xml_tenant_enabled_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_routes');
    }
};
