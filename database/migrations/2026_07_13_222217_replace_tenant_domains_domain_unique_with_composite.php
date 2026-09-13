<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the global unique constraint on tenant_domains.domain
     * with a composite unique index on (domain, purpose, tenant_id).
     *
     * This allows the same domain name to be shared across multiple
     * tenants while still preventing duplicate registrations within
     * the same tenant for the same purpose.
     */
    public function up(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table) {
            // Drop the global unique constraint on domain alone
            $table->dropUnique('tenant_domains_domain_unique');

            // Composite unique: same domain allowed across tenants,
            // but not duplicated for the same tenant and purpose
            $table->unique(['domain', 'purpose', 'tenant_id'], 'tenant_domains_domain_purpose_tenant_unique');
        });
    }

    /**
     * Reverse the migration — restore global domain uniqueness.
     */
    public function down(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->dropUnique('tenant_domains_domain_purpose_tenant_unique');
            $table->unique('domain');
        });
    }
};
