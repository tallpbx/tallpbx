<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration to create the certificate_dns_credentials table.
 *
 * Stores encrypted third-party DNS provider API credentials (such as Cloudflare API tokens)
 * used by Certbot for automated DNS-01 ACME challenge validations and wildcard certificates.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('certificate_dns_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->comment('Human-readable credential identifier e.g. Cloudflare Primary');
            $table->string('provider', 30)->default('cloudflare')->comment('DNS provider key e.g. cloudflare');
            $table->text('credentials')->comment('Encrypted JSON payload containing API tokens or secret keys');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certificate_dns_credentials');
    }
};
