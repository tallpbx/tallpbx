<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration to create the security_services table.
 *
 * Stores the PBX Port Catalog definitions (standard system services like SIP, RTP,
 * Web Admin, SSH, ESL, as well as user-defined custom service ports).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('security_services', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('description', 255)->nullable();
            $table->enum('protocol', ['tcp', 'udp', 'both'])->default('both');
            $table->string('port_range', 100)->comment('Single port, comma-separated list, or range e.g. 16384-32768');
            $table->boolean('is_system')->default(false)->comment('Whether this is a core PBX system service');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_services');
    }
};
