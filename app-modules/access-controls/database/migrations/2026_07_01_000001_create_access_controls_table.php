<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Access controls define network-level allow/deny rules (CIDR ranges,
     * domains, or IPs) used by FreeSWITCH's ACL system. Each rule can have
     * multiple nodes (specific IPs, CIDRs, or domains) to check.
     */
    public function up(): void
    {
        Schema::create('access_controls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('action')->default('allow');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('access_control_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('access_control_id');
            $table->string('type')->default('cidr');
            $table->string('value');
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->foreign('access_control_id')
                ->references('id')->on('access_controls')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_control_nodes');
        Schema::dropIfExists('access_controls');
    }
};
