<?php

declare(strict_types=1);

namespace App\Listeners\FreeSwitch;

use App\Events\FreeSwitch\RecordStop;
use App\Models\Tenant;
use App\Services\DialplanContext;
use Illuminate\Support\Facades\Log;
use Modules\CallRecordings\Services\CallRecordingService;
use Throwable;

/**
 * Registers completed call recordings from trusted FreeSWITCH spool paths.
 */
class RegisterCompletedCallRecording
{
    /**
     * Create the listener with tenant-context and media lifecycle services.
     */
    public function __construct(
        private readonly CallRecordingService $recordings,
        private readonly DialplanContext $dialplanContext,
    ) {}

    /**
     * Archive a valid completed recording without blocking on remote transfer.
     */
    public function handle(RecordStop $event): void
    {
        $tenantId = $this->resolveTenantId($event);
        $path = $event->header('Record-File-Path');
        $callUuid = $event->callUuid();

        if ($tenantId === null || $path === null || $callUuid === null || ! $this->isCompletedRecordingPath($path, $tenantId)) {
            $this->logIgnored($event, $tenantId);

            return;
        }

        try {
            $this->recordings->archiveCompleted([
                'tenant_id' => $tenantId,
                'call_uuid' => $callUuid,
                'file_path' => realpath($path),
                'caller_id' => $event->header('variable_effective_caller_id_number'),
                'caller_id_name' => $event->header('variable_effective_caller_id_name'),
                'destination' => $event->header('variable_destination_number'),
                'duration' => max(0, (int) $event->header('Record-Seconds', '0')),
                'original_filename' => basename($path),
                'mime_type' => $this->mimeType($path),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Call recording archive registration failed.', [
                'call_uuid' => $callUuid,
                'tenant_id' => $tenantId,
                'error' => mb_substr($exception->getMessage(), 0, 500),
            ]);
        }
    }

    /**
     * Resolve the tenant from an explicit event value or a matching context.
     */
    private function resolveTenantId(RecordStop $event): ?int
    {
        $explicit = $event->header('variable_tenant_id');
        $explicitId = $explicit !== null && ctype_digit($explicit) ? (int) $explicit : null;
        $contextId = $this->dialplanContext->parseTenantId((string) $event->header('variable_user_context', ''));

        if ($explicitId !== null && $contextId !== null && (string) $explicitId !== $contextId) {
            return null;
        }

        $tenantId = $explicitId ?? ($contextId !== null && ctype_digit($contextId) ? (int) $contextId : null);

        return $tenantId !== null && Tenant::query()->whereKey($tenantId)->exists() ? $tenantId : null;
    }

    /**
     * Confirm that FreeSWITCH completed a non-empty file below this tenant root.
     */
    private function isCompletedRecordingPath(string $path, int $tenantId): bool
    {
        $root = realpath(rtrim((string) config('media-storage.spool_root'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$tenantId.DIRECTORY_SEPARATOR.'call-recording');
        $resolvedPath = realpath($path);

        return $root !== false
            && $resolvedPath !== false
            && str_starts_with($resolvedPath, $root.DIRECTORY_SEPARATOR)
            && is_file($resolvedPath)
            && filesize($resolvedPath) > 0;
    }

    /**
     * Resolve a safe server-detected MIME type for the persisted metadata.
     */
    private function mimeType(string $path): string
    {
        return mime_content_type($path) ?: 'application/octet-stream';
    }

    /**
     * Record bounded operational context without exposing media paths.
     */
    private function logIgnored(RecordStop $event, ?int $tenantId): void
    {
        Log::warning('Ignored invalid completed call recording event.', [
            'call_uuid' => $event->callUuid(),
            'tenant_id' => $tenantId,
            'has_recording_path' => $event->header('Record-File-Path') !== null,
        ]);
    }
}
