<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_broadcasts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        Schema::create('call_broadcast_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('broadcast_id')->constrained('call_broadcasts')->cascadeOnDelete();
            $table->string('phone_number');
            $table->uuid('originate_uuid')->nullable()->index();
            $table->string('call_status')->default('pending');
            $table->string('hangup_cause')->nullable();
            $table->unsignedInteger('call_duration')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_broadcast_recipients');
        Schema::dropIfExists('call_broadcasts');
    }
};
