<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `follow_me` table for storing follow-me call
     * forwarding rules. Each record defines an extension that
     * should forward calls to a destination when unanswered.
     */
    public function up(): void
    {
        Schema::create('follow_me', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('extension');
            $table->string('destination');
            $table->unsignedInteger('ring_timeout')->default(30);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled', 'extension'], 'follow_me_xml_tenant_enabled_extension_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the follow_me table.
     */
    public function down(): void
    {
        Schema::dropIfExists('follow_me');
    }
};
