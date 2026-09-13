<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_recordings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('caller_id')->nullable();
            $table->string('caller_id_name')->nullable();
            $table->string('destination')->nullable();
            $table->unsignedInteger('duration')->default(0);
            $table->string('file_path');
            $table->string('call_uuid')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_recordings');
    }
};
