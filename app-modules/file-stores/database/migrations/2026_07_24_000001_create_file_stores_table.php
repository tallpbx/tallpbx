<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the system-owned file store destination profiles table.
     */
    public function up(): void
    {
        Schema::create('file_stores', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('provider');
            $table->text('settings');
            $table->timestamps();
        });
    }

    /**
     * Remove file store destination profiles.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_stores');
    }
};
