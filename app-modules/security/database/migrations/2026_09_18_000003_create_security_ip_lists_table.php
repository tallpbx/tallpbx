<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration to create the security_ip_lists table.
 *
 * Stores trusted IP addresses (whitelist) that are always permitted and never blocked,
 * and malicious IP addresses (blacklist) that are immediately dropped at network ingress.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('security_ip_lists', function (Blueprint $table): void {
            $table->id();
            $table->enum('type', ['whitelist', 'blacklist'])->default('whitelist')->comment('whitelist (trusted) or blacklist (blocked)');
            $table->string('ip_address', 100)->comment('IPv4 address or CIDR subnet (e.g. 192.168.1.0/24)');
            $table->string('description', 255)->nullable()->comment('Plain-language label e.g. Office Network, Carrier Trunk');
            $table->timestamps();

            $table->unique(['type', 'ip_address'], 'idx_security_ip_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_ip_lists');
    }
};
