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
     * Creates the `call_blocks` table for storing caller ID
     * block rules. Each rule matches an incoming caller ID
     * pattern and blocks the call.
     */
    public function up(): void
    {
        Schema::create('call_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('caller_id_number');
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled', 'name'], 'call_blocks_xml_tenant_enabled_name_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_blocks');
    }
};
