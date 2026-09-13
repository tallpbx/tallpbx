<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('dial_pattern');
            $table->string('gateway')->nullable();
            $table->foreignUuid('gateway_id')->nullable()->constrained('gateways')->nullOnDelete();
            $table->string('caller_id_name')->nullable();
            $table->string('caller_id_number')->nullable();
            $table->integer('priority')->default(100);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'dial_pattern']);
            $table->index(['tenant_id', 'priority']);
            $table->index(['tenant_id', 'enabled', 'priority'], 'outbound_routes_xml_tenant_enabled_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_routes');
    }
};
