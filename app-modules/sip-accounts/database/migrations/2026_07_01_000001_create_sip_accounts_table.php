<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SIP accounts are the technical authentication identities FreeSWITCH uses
     * to register endpoints. Each account maps to one extension and supports
     * three identity modes: global_username, domain_username, and hybrid.
     */
    public function up(): void
    {
        Schema::create('sip_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('extension_id')->nullable();
            $table->foreignUuid('tenant_domain_id')->nullable()->constrained('tenant_domains')->nullOnDelete();
            $table->string('identity_mode')->default('global_username');
            $table->string('auth_username');
            $table->text('auth_password');
            $table->string('global_auth_key')->unique();
            $table->string('user_context')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'extension_id']);
            $table->index(['tenant_domain_id', 'auth_username']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sip_accounts');
    }
};
