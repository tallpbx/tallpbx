<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SIP profiles define the FreeSWITCH Sofia profiles — internal, external,
     * or custom — that control which IP/port the PBX listens on and how
     * SIP traffic is handled. Settings are stored as JSON for maximum flexibility.
     */
    public function up(): void
    {
        Schema::create('sip_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sip_profiles');
    }
};
