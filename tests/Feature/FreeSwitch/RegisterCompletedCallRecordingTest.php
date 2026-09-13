<?php

declare(strict_types=1);

use App\Events\FreeSwitch\RecordStop;
use App\Listeners\FreeSwitch\RegisterCompletedCallRecording;
use App\Models\Tenant;
use Illuminate\Support\Facades\File;
use Modules\CallRecordings\Models\CallRecording;
use Modules\FileStores\Enums\MediaAssetStatus;

beforeEach(function (): void {
    $this->mediaRoot = storage_path('framework/testing/completed-call-recordings');
    config([
        'media-storage.store_root' => $this->mediaRoot.'/store',
        'media-storage.spool_root' => $this->mediaRoot.'/spool',
    ]);
    $this->tenant = Tenant::factory()->create();
    $this->recordingDirectory = $this->mediaRoot.'/spool/'.$this->tenant->id.'/call-recording';
    File::ensureDirectoryExists($this->recordingDirectory);
});

afterEach(function (): void {
    File::deleteDirectory($this->mediaRoot);
});

it('archives a completed non-empty recording and removes the FreeSWITCH spool', function (): void {
    $path = $this->recordingDirectory.'/completed.wav';
    File::put($path, 'completed call recording');

    app(RegisterCompletedCallRecording::class)->handle(recordStopEvent($this->tenant->id, $path));

    $recording = CallRecording::withoutGlobalScope('tenant')->firstOrFail();
    $asset = $recording->mediaAsset;

    expect($recording->call_uuid)->toBe('call-uuid-001')
        ->and($recording->duration)->toBe(12)
        ->and($asset)->not->toBeNull()
        ->and($asset->status)->toBe(MediaAssetStatus::Available)
        ->and($asset->fileStore->provider)->toBe('local')
        ->and(is_file($path))->toBeFalse();
});

it('ignores empty, out-of-root, and duplicate recording completion events', function (): void {
    $emptyPath = $this->recordingDirectory.'/empty.wav';
    File::put($emptyPath, '');
    $outsidePath = $this->mediaRoot.'/outside.wav';
    File::put($outsidePath, 'outside root');

    $listener = app(RegisterCompletedCallRecording::class);
    $listener->handle(recordStopEvent($this->tenant->id, $emptyPath));
    $listener->handle(recordStopEvent($this->tenant->id, $outsidePath));

    $path = $this->recordingDirectory.'/duplicate.wav';
    File::put($path, 'completed call recording');
    $event = recordStopEvent($this->tenant->id, $path);
    $listener->handle($event);
    $listener->handle($event);

    expect(CallRecording::withoutGlobalScope('tenant')->count())->toBe(1);
});

it('falls back to an unambiguous tenant context and rejects mismatched identity', function (): void {
    $path = $this->recordingDirectory.'/context.wav';
    File::put($path, 'completed call recording');
    $listener = app(RegisterCompletedCallRecording::class);
    $listener->handle(recordStopEvent(null, $path, 'tenant_'.$this->tenant->id.'_internal'));

    $mismatchedPath = $this->recordingDirectory.'/mismatched.wav';
    File::put($mismatchedPath, 'completed call recording');
    $listener->handle(recordStopEvent($this->tenant->id + 999, $mismatchedPath, 'tenant_'.$this->tenant->id.'_internal'));

    expect(CallRecording::withoutGlobalScope('tenant')->count())->toBe(1);
});

/**
 * Build the production RECORD_STOP header shape used by the ESL listener.
 */
function recordStopEvent(?int $tenantId, string $path, ?string $context = null): RecordStop
{
    return new RecordStop('RECORD_STOP', array_filter([
        'Unique-ID' => 'call-uuid-001',
        'Record-File-Path' => $path,
        'Record-Seconds' => '12',
        'variable_tenant_id' => $tenantId === null ? null : (string) $tenantId,
        'variable_user_context' => $context,
        'variable_effective_caller_id_number' => '15551234567',
        'variable_effective_caller_id_name' => 'Test Caller',
        'variable_destination_number' => '1000',
    ], static fn (?string $value): bool => $value !== null), '');
}
