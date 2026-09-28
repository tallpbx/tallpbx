<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table storing number translation rules per tenant.
     */
    public function up(): void
    {
        Schema::create('number_translations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('order')->default(0);
            $table->string('match_pattern');
            $table->string('replace_pattern')->nullable();
            $table->string('direction')->default('outbound');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Remove the number translations table.
     */
    public function down(): void
    {
        Schema::dropIfExists('number_translations');
    }
};
