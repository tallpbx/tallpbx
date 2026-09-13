<?php

declare(strict_types=1);

use App\Events\FreeSwitch\ChannelExecuteComplete;
use App\Listeners\FreeSwitch\RegisterCompletedVoicemailMessage;
use App\Models\Tenant;
use Illuminate\Support\Facades\File;
use Modules\VoicemailMessages\Models\VoicemailMessage;
use Modules\Voicemails\Models\Voicemail;

beforeEach(function (): void {
    $this->root = storage_path('framework/testing/voicemail-storage');
    config(['media-storage.store_root' => $this->root.'/store']);
    $this->tenant = Tenant::factory()->create();
    $this->mailbox = Voicemail::withoutGlobalScope('tenant')->create([
        'tenant_id' => $this->tenant->id,
        'voicemail_id' => '1000',
        'mailbox' => '1000',
        'enabled' => true,
    ]);
    $this->path = $this->root.'/store/runtime/'.$this->tenant->id.'/voicemail-message/tenant.test/1000/inbox/019fb000-0000-7000-8000-000000000001.wav';
    File::ensureDirectoryExists(dirname($this->path));
    File::put($this->path, 'voicemail audio');
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('registers a completed voicemail as local-only media', function (): void {
    app(RegisterCompletedVoicemailMessage::class)->handle(voicemailCompleteEvent($this->tenant->id, $this->path));

    $message = VoicemailMessage::withoutGlobalScope('tenant')->firstOrFail();

    expect($message->voicemail_id)->toBe($this->mailbox->id)
        ->and($message->freeswitch_message_uuid)->toBe('019fb000-0000-7000-8000-000000000001')
        ->and($message->mediaAsset)->not->toBeNull()
        ->and($message->mediaAsset->fileStore->provider)->toBe('local')
        ->and(is_file($this->path))->toBeTrue();
});

it('registers a voicemail saved with the mod_voicemail msg_ filename prefix', function (): void {
    $msgPath = $this->root.'/store/runtime/'.$this->tenant->id.'/voicemail-message/tenant.test/1000/inbox/msg_019fb000-0000-7000-8000-000000000001.wav';
    File::ensureDirectoryExists(dirname($msgPath));
    File::put($msgPath, 'voicemail audio');

    app(RegisterCompletedVoicemailMessage::class)->handle(voicemailCompleteEvent($this->tenant->id, $msgPath));

    $message = VoicemailMessage::withoutGlobalScope('tenant')->firstOrFail();

    expect($message->freeswitch_message_uuid)->toBe('019fb000-0000-7000-8000-000000000001');
});

it('registers a deposit event carrying mod_voicemail account variables', function (): void {
    // mod_voicemail's deposit path sets voicemail_account and voicemail_domain
    // channel variables (never voicemail_id); the listener must accept that
    // real event shape and fall back to the legacy names only when needed.
    $event = new ChannelExecuteComplete('CHANNEL_EXECUTE_COMPLETE', [
        'Application' => 'voicemail',
        'variable_tenant_id' => (string) $this->tenant->id,
        'variable_voicemail_account' => '1000',
        'variable_voicemail_domain' => 'tenant.test',
        'variable_voicemail_folder' => 'inbox',
        'variable_voicemail_file_path' => $this->path,
        'variable_voicemail_message_len' => '12',
        'Caller-Caller-ID-Number' => '15551234567',
        'Caller-Caller-ID-Name' => 'Test Caller',
    ], '');

    app(RegisterCompletedVoicemailMessage::class)->handle($event);

    $message = VoicemailMessage::withoutGlobalScope('tenant')->firstOrFail();

    expect($message->voicemail_id)->toBe($this->mailbox->id)
        ->and($message->freeswitch_domain)->toBe('tenant.test');
});

it('ignores non-voicemail, ambiguous, out-of-root, and duplicate completion events', function (): void {
    $listener = app(RegisterCompletedVoicemailMessage::class);
    $listener->handle(new ChannelExecuteComplete('CHANNEL_EXECUTE_COMPLETE', ['Application' => 'playback'], ''));
    $listener->handle(voicemailCompleteEvent($this->tenant->id + 1, $this->path));
    $listener->handle(voicemailCompleteEvent($this->tenant->id, $this->root.'/outside.wav'));
    $listener->handle(voicemailCompleteEvent($this->tenant->id, $this->path));
    $listener->handle(voicemailCompleteEvent($this->tenant->id, $this->path));

    expect(VoicemailMessage::withoutGlobalScope('tenant')->count())->toBe(1);
});

/**
 * Build a ChannelExecuteComplete event for FreeSWITCH's voicemail application.
 */
function voicemailCompleteEvent(int $tenantId, string $path): ChannelExecuteComplete
{
    return new ChannelExecuteComplete('CHANNEL_EXECUTE_COMPLETE', [
        'Application' => 'voicemail',
        'variable_tenant_id' => (string) $tenantId,
        'variable_voicemail_id' => '1000',
        'variable_domain_name' => 'tenant.test',
        'variable_voicemail_folder' => 'inbox',
        'variable_voicemail_file_path' => $path,
        'variable_voicemail_message_len' => '12',
        'Caller-Caller-ID-Number' => '15551234567',
        'Caller-Caller-ID-Name' => 'Test Caller',
    ], '');
}
