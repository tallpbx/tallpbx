<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Illuminate\Support\Facades\File;
use Modules\Fax\Models\FaxInbox;
use Modules\Fax\Services\FaxServiceInterface;
use Modules\FileStores\Enums\MediaAssetStatus;

beforeEach(function (): void {
    $this->mediaRoot = storage_path('framework/testing/fax-storage');
    config([
        'media-storage.store_root' => $this->mediaRoot.'/store',
        'media-storage.spool_root' => $this->mediaRoot.'/spool',
    ]);
    $this->tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);
});

afterEach(function (): void {
    app(TenantManager::class)->clear();
    File::deleteDirectory($this->mediaRoot);
});

it('keeps an outbound fax local until its delivery reaches a terminal state', function (): void {
    $source = faxPdf($this->mediaRoot.'/source.pdf');
    $service = app(FaxServiceInterface::class);

    $fax = $service->send([
        'tenant_id' => $this->tenant->id,
        'fax_number' => '+15551234567',
        'source_path' => $source,
        'original_filename' => 'send.pdf',
    ]);

    expect($fax->status)->toBe('pending')
        ->and($fax->mediaAsset)->not->toBeNull()
        ->and($fax->mediaAsset->status)->toBe(MediaAssetStatus::Available)
        ->and($fax->mediaAsset->fileStore->provider)->toBe('local');

    $service->completeOutgoing($fax, 'sent');

    expect($fax->fresh()->status)->toBe('sent')
        ->and($fax->fresh()->mediaAsset->status)->toBe(MediaAssetStatus::Available);
});

it('archives a trusted non-empty inbound PDF only after successful receipt', function (): void {
    $path = $this->mediaRoot.'/spool/'.$this->tenant->id.'/fax-inbound/received.pdf';
    faxPdf($path);

    $fax = app(FaxServiceInterface::class)->receive([
        'tenant_id' => $this->tenant->id,
        'caller_id' => '+15557654321',
        'pages' => 1,
        'source_path' => $path,
        'original_filename' => 'received.pdf',
    ]);

    expect($fax)->toBeInstanceOf(FaxInbox::class)
        ->and($fax->mediaAsset)->not->toBeNull()
        ->and($fax->mediaAsset->status)->toBe(MediaAssetStatus::Available)
        ->and(is_file($path))->toBeFalse();
});

/**
 * Create a minimal non-empty PDF beneath a test-controlled path.
 */
function faxPdf(string $path): string
{
    File::ensureDirectoryExists(dirname($path));
    File::put($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");

    return $path;
}
