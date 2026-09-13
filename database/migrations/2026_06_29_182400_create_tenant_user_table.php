<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the database migration.
     */
    public function up(): void
    {
        Schema::create('tenant_user', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('member');
            $table->boolean('primary')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
        });
    }

    /**
     * Reverse the database migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_user');
    }
};
