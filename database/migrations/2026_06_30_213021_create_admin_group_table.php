<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the admin_group pivot table.
     *
     * Allows admins to belong to groups for permission assignment,
     * e.g. granting the admin.impersonate permission.
     */
    public function up(): void
    {
        Schema::create('admin_group', function (Blueprint $table) {
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->foreignUuid('group_id')->constrained('groups')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['admin_id', 'group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_group');
    }
};
