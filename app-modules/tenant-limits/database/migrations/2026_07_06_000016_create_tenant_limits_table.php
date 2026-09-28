<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table storing soft and hard resource limits per tenant.
     */
    public function up(): void
    {
        Schema::create('tenant_limits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('resource');
            $table->unsignedInteger('soft_limit');
            $table->unsignedInteger('hard_limit');
            $table->timestamps();
        });
    }

    /**
     * Remove the tenant limits table.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_limits');
    }
};
