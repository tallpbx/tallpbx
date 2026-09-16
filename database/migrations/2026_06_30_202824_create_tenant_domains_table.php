<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the tenant_domains table for SIP identity realms and aliases.
     */
    public function up(): void
    {
        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('domain');
            $table->string('purpose')->default('sip_realm'); // sip_realm, provisioning, web, alias
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['domain', 'purpose', 'tenant_id'], 'tenant_domains_domain_purpose_tenant_unique');
        });
    }

    /**
     * Reverse the database migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
    }
};
