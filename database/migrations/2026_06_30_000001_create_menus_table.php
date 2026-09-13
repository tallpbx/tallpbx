<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: create the menus table.
     *
     * Stores the hierarchical menu tree with UUID-based parent/child
     * relationships. Each menu item belongs to a guard (admin/web)
     * and may carry a permission gate requirement.
     */
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('parent_id')
                ->nullable()
                ->constrained('menus')
                ->cascadeOnDelete();
            $table->string('module_name');
            $table->string('key')->unique();
            $table->string('label');
            $table->string('route')->nullable();
            $table->string('icon')->nullable();
            $table->string('guard')->default('web');
            $table->integer('order')->default(0);
            $table->string('permission')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
