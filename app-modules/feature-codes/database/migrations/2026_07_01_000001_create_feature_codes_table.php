<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Feature codes are star codes like *97 for voicemail that trigger
     * specific actions in the dialplan. Each tenant can customize their
     * own feature codes.
     */
    public function up(): void
    {
        Schema::create('feature_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('application')->nullable();
            $table->string('application_data')->nullable();
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'enabled', 'code'], 'feature_codes_xml_tenant_enabled_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_codes');
    }
};
