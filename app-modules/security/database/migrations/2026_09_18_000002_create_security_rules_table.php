<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration to create the security_rules table.
 *
 * Stores sequential host firewall filtering rules evaluated in order (sequence 10, 20, 30...).
 * Rules can target a cataloged service (via service_id) or a custom port/protocol.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('security_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('sequence')->default(100)->comment('Order of evaluation (10, 20, 30...)');
            $table->string('description', 255);
            $table->string('source_ip', 100)->default('any')->comment('Single IP, CIDR (e.g. 192.168.1.0/24), or "any"');
            $table->foreignId('service_id')->nullable()->constrained('security_services')->nullOnDelete()->comment('Foreign key to security_services or NULL for custom port');
            $table->string('custom_port', 100)->nullable();
            $table->enum('custom_protocol', ['all', 'tcp', 'udp'])->nullable();
            $table->string('action', 20)->default('accept')->comment('Firewall action: accept or drop');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['sequence', 'enabled'], 'idx_security_rules_sequence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_rules');
    }
};
