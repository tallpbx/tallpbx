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
     * Creates the `ivr_menus` table for storing Interactive Voice Response
     * menu configurations and the `ivr_menu_options` table for individual
     * digit-to-action mappings within each menu. Each menu belongs to a
     * tenant and can have multiple options (e.g., "press 1 for Sales").
     */
    public function up(): void
    {
        Schema::create('ivr_menus', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->text('greeting')->nullable();
            $table->integer('timeout')->default(10);
            $table->integer('max_failures')->default(3);
            $table->integer('digit_length')->default(0);
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled', 'name'], 'ivr_menus_xml_tenant_enabled_name_idx');
        });

        Schema::create('ivr_menu_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ivr_menu_id')->constrained('ivr_menus')->cascadeOnDelete();
            $table->string('digit', 10);
            $table->string('action');
            $table->string('action_data')->nullable();
            $table->integer('order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['ivr_menu_id', 'digit']);
            $table->index(['ivr_menu_id', 'order'], 'ivr_menu_options_xml_menu_order_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ivr_menu_options');
        Schema::dropIfExists('ivr_menus');
    }
};
