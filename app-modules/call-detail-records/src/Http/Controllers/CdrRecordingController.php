<?php

declare(strict_types=1);

namespace Modules\CallDetailRecords\Http\Controllers;

use App\Services\TenantManager;
use App\Support\PanelActor;
use Modules\CallDetailRecords\Models\Cdr;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams one call recording for playback in the panel.
 *
 * The stored path comes from FreeSWITCH, so it is joined to the configured
 * recording root when relative, canonicalized with realpath(), and required
 * to live inside one of the recording roots before anything is served — a
 * recorded path can never escape into the rest of the filesystem.
 */
class CdrRecordingController
{
    /** Audio content types served by file extension; anything else is generic binary. */
    private const AUDIO_TYPES = [
        'wav' => 'audio/wav',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'opus' => 'audio/ogg',
        'm4a' => 'audio/mp4',
    ];

    /**
     * Stream the recording attached to one call detail record.
     */
    public function show(string $cdrId): BinaryFileResponse
    {
        // Administrators may open any record; tenant users only see records
        // of their active tenant, so a foreign id is hidden as "not found"
        // instead of disclosing that another tenant owns this record.
        $cdr = Cdr::withoutGlobalScope('tenant')
            ->when(! PanelActor::isAdmin(), fn ($q) => $q->where('tenant_id', app(TenantManager::class)->getTenantId()))
            ->findOrFail($cdrId);

        $path = $this->resolveRecordingPath((string) $cdr->record_path);

        if ($path === null) {
            abort(404);
        }

        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return response()->file($path, [
            'Content-Type' => self::AUDIO_TYPES[$extension] ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Resolve a stored recording path to a canonical file inside the roots.
     */
    private function resolveRecordingPath(string $recordPath): ?string
    {
        if ($recordPath === '') {
            return null;
        }

        // Relative paths resolve against the media store root; absolute paths
        // are used as-is. Either way the canonical path must stay inside one
        // of the configured recording roots.
        $candidate = str_starts_with($recordPath, '/')
            ? $recordPath
            : rtrim((string) config('media-storage.store_root'), '/').'/'.$recordPath;

        $realPath = realpath($candidate);

        if ($realPath === false || ! is_file($realPath)) {
            return null;
        }

        $roots = array_filter([
            (string) realpath((string) config('media-storage.store_root')),
            (string) realpath((string) config('media-storage.spool_root')),
        ]);

        foreach ($roots as $root) {
            if (str_starts_with($realPath, rtrim($root, '/').'/')) {
                return $realPath;
            }
        }

        return null;
    }
}
