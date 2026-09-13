<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extensions are the human-facing phone numbers within a tenant.
     * Extension numbers are only unique within a tenant — different tenants
     * can both have extension 101. Each extension may optionally be linked
     * to a SIP account for registration.
     */
    public function up(): void
    {
        Schema::create('extensions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('extension_number');
            $table->string('number_alias')->nullable();
            $table->string('display_name')->nullable();
            $table->boolean('voicemail_enabled')->default(false);
            $table->string('accountcode')->nullable();
            $table->string('effective_caller_id_name')->nullable();
            $table->string('effective_caller_id_number')->nullable();
            $table->string('outbound_caller_id_name')->nullable();
            $table->string('outbound_caller_id_number')->nullable();
            $table->string('emergency_caller_id_name')->nullable();
            $table->string('emergency_caller_id_number')->nullable();
            $table->string('directory_first_name')->nullable();
            $table->string('directory_last_name')->nullable();
            $table->boolean('directory_visible')->default(true);
            $table->boolean('directory_exten_visible')->default(true);
            $table->unsignedInteger('max_registrations')->nullable();
            $table->unsignedInteger('limit_max')->nullable();
            $table->string('limit_destination')->nullable();
            $table->string('missed_call_app')->nullable();
            $table->string('missed_call_data')->nullable();
            $table->string('user_context')->nullable();
            $table->string('toll_allow')->nullable();
            $table->unsignedInteger('call_timeout')->nullable();
            $table->string('call_group')->nullable();
            $table->boolean('call_screen_enabled')->default(false);
            $table->string('user_record')->nullable();
            $table->string('hold_music')->nullable();
            $table->string('auth_acl')->nullable();
            $table->string('cidr')->nullable();
            $table->string('sip_force_contact')->nullable();
            $table->unsignedInteger('sip_force_expires')->nullable();
            $table->string('nibble_account')->nullable();
            $table->string('mwi_account')->nullable();
            $table->string('sip_bypass_media')->nullable();
            $table->text('absolute_codec_string')->nullable();
            $table->boolean('force_ping')->default(false);
            $table->text('dial_string')->nullable();
            $table->string('extension_language')->nullable();
            $table->string('extension_dialect')->nullable();
            $table->string('extension_voice')->nullable();
            $table->string('extension_type')->default('default');
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'extension_number']);
            $table->index(['tenant_id', 'number_alias']);
        });

        Schema::create('extension_user', function (Blueprint $table) {
            $table->foreignUuid('extension_id')->constrained('extensions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['extension_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_user');
        Schema::dropIfExists('extensions');
    }
};
