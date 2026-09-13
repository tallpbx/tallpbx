<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_flows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('extension');
            $table->string('destination_type')->nullable();
            $table->string('destination_id')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled', 'extension'], 'call_flows_xml_tenant_enabled_extension_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_flows');
    }
};
