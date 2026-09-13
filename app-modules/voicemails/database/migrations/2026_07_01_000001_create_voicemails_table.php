<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Voicemail mailboxes store per-extension voicemail settings.
     * Each voicemail belongs to a tenant and is identified by a
     * unique voicemail_id (mailbox number) within that tenant.
     * The mailbox field is the actual mailbox identifier used
     * by FreeSWITCH for message storage and retrieval.
     */
    public function up(): void
    {
        Schema::create('voicemails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('voicemail_id');
            $table->string('mailbox');
            $table->string('name')->nullable();
            $table->string('password')->nullable();
            $table->string('email')->nullable();
            $table->text('greeting_message')->nullable();
            $table->boolean('require_password')->default(true);
            $table->boolean('forward_to_email')->default(false);
            $table->boolean('delete_after_email')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'voicemail_id']);
            $table->index(['tenant_id', 'enabled', 'voicemail_id'], 'voicemails_xml_tenant_enabled_mailbox_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voicemails');
    }
};
