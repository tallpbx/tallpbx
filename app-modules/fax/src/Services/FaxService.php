<?php

declare(strict_types=1);

namespace Modules\Fax\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\Fax\Models\FaxInbox;
use Modules\Fax\Models\FaxOutgoing;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Services\MediaStorageServiceInterface;
use RuntimeException;

class FaxService implements FaxServiceInterface
{
    /**
     * Create the fax lifecycle service with managed-media storage.
     */
    public function __construct(private readonly MediaStorageServiceInterface $mediaStorage) {}

    public function send(array $data): FaxOutgoing
    {
        return DB::transaction(function () use ($data): FaxOutgoing {
            $fax = FaxOutgoing::withoutGlobalScope('tenant')->create([
                'tenant_id' => $data['tenant_id'],
                'fax_number' => $data['fax_number'],
                'document_path' => '',
                'status' => 'pending',
            ]);
            $asset = $this->mediaStorage->storeLocal(
                $fax,
                MediaCategory::FaxOutbound,
                $data['source_path'],
                $data['original_filename'],
                'application/pdf',
            );
            $path = $this->mediaStorage->resolveLocalPath($asset->id);

            if ($path === null) {
                throw new RuntimeException('Outgoing fax spool must remain locally available.');
            }

            $fax->update(['document_path' => $path]);

            return $fax->fresh(['mediaAsset']);
        });
    }

    /**
     * Register a trusted inbound PDF only after a successful receipt boundary.
     */
    public function receive(array $data): FaxInbox
    {
        $sourcePath = $this->trustedInboundPdfPath((int) $data['tenant_id'], (string) $data['source_path']);
        $fax = DB::transaction(function () use ($data, $sourcePath): FaxInbox {
            $fax = FaxInbox::withoutGlobalScope('tenant')->create([
                'tenant_id' => $data['tenant_id'],
                'caller_id' => $data['caller_id'] ?? null,
                'pages' => $data['pages'] ?? 1,
                'document_path' => $sourcePath,
                'received_at' => now(),
            ]);
            $this->mediaStorage->archiveCompleted($fax, MediaCategory::FaxInbound, $sourcePath, $data['original_filename'], 'application/pdf');

            return $fax;
        });

        File::delete($sourcePath);

        return $fax->fresh(['mediaAsset']);
    }

    /**
     * Archive an outbound local spool after the fax provider reports a terminal result.
     */
    public function completeOutgoing(FaxOutgoing $fax, string $status): void
    {
        if (! in_array($status, ['sent', 'failed'], true)) {
            throw new RuntimeException('Fax status is not terminal.');
        }

        DB::transaction(function () use ($fax, $status): void {
            $asset = $fax->mediaAsset;

            if ($asset === null) {
                throw new RuntimeException('Outgoing fax has no managed local spool.');
            }

            $localPath = $this->mediaStorage->resolveLocalPath($asset->id);

            if ($localPath === null) {
                throw new RuntimeException('Outgoing fax spool is unavailable.');
            }

            $this->mediaStorage->archiveCompleted($fax, MediaCategory::FaxOutbound, $localPath, basename($fax->document_path), 'application/pdf');
            $fax->update(['status' => $status, 'sent_at' => now()]);
        });
    }

    public function deleteInbox(FaxInbox $fax): void
    {
        DB::transaction(function () use ($fax): void {
            if ($fax->mediaAsset !== null) {
                $this->mediaStorage->requestDeletion($fax->mediaAsset->id);
            }
            $fax->delete();
        });
    }

    /**
     * Validate that an inbound document is a non-empty PDF in its managed tenant root.
     */
    private function trustedInboundPdfPath(int $tenantId, string $path): string
    {
        $root = realpath(rtrim((string) config('media-storage.spool_root'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$tenantId.DIRECTORY_SEPARATOR.'fax-inbound');
        $resolved = realpath($path);

        if ($root === false || $resolved === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) || ! is_file($resolved) || filesize($resolved) <= 0 || ! str_starts_with((string) file_get_contents($resolved, false, null, 0, 5), '%PDF-')) {
            throw new RuntimeException('Inbound fax must be a non-empty PDF beneath the managed fax root.');
        }

        return $resolved;
    }
}
