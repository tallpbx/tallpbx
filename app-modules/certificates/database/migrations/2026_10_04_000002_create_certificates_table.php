<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration to create the certificates table.
 *
 * Central inventory of all SSL/TLS certificates (Let's Encrypt, Custom PEM, Self-Signed).
 * Tracks cryptographic validity, service assignments (Web Nginx / Telephony FreeSWITCH),
 * ACME challenge details, and on-disk storage locations.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->comment('Human-readable display name e.g. Primary Let\'s Encrypt');
            $table->string('type', 30)->comment('lets_encrypt, custom, self_signed');
            $table->string('common_name')->comment('Primary Fully Qualified Domain Name or IP');
            $table->json('san_domains')->nullable()->comment('Subject Alternative Names array');
            $table->string('issuer')->nullable()->comment('Issuing Certificate Authority name');
            $table->timestamp('valid_from')->nullable()->comment('Certificate validity start');
            $table->timestamp('valid_to')->nullable()->comment('Certificate validity expiration');
            $table->string('serial_number')->nullable()->comment('X.509 serial number');
            $table->string('fingerprint_sha256', 64)->nullable()->comment('SHA-256 fingerprint digest');

            // Service deployment bindings
            $table->boolean('is_default_web')->default(false)->index()->comment('Active certificate serving Nginx HTTPS and Reverb WebSockets');
            $table->boolean('is_default_telephony')->default(false)->index()->comment('Active certificate serving FreeSWITCH SIP TLS and WebRTC WSS');

            // ACME / Let\'s Encrypt details
            $table->string('challenge_type', 20)->nullable()->comment('http-01, dns-01');
            $table->foreignId('dns_credential_id')->nullable()->constrained('certificate_dns_credentials')->nullOnDelete()->comment('Associated DNS API credential for DNS-01');
            $table->boolean('auto_renew')->default(true)->comment('Whether scheduled task should renew this certificate');
            $table->boolean('is_staging')->default(false)->comment('Whether issued via Let\'s Encrypt staging environment');
            $table->timestamp('last_renewed_at')->nullable()->comment('Timestamp of last successful renewal');
            $table->text('last_renew_error')->nullable()->comment('Error message from last failed renewal attempt');

            // Filesystem storage reference
            $table->string('storage_identifier', 64)->unique()->comment('Safe filesystem directory name under /etc/tallpbx/certs/');

            $table->timestamps();

            $table->index(['type', 'valid_to'], 'idx_certificates_type_valid_to');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
