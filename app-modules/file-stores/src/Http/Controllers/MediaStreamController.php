<?php

declare(strict_types=1);

namespace Modules\FileStores\Http\Controllers;

use Modules\FileStores\Models\MediaAsset;
use Modules\FileStores\Services\MediaAssetAuthorizationService;
use Modules\FileStores\Services\MediaStorageServiceInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves authorized managed media without exposing provider URLs.
 */
class MediaStreamController
{
    /**
     * Stream media for in-browser playback.
     */
    public function stream(string $mediaAssetId, MediaAssetAuthorizationService $authorization, MediaStorageServiceInterface $mediaStorage): StreamedResponse
    {
        return $this->respond($authorization->authorize($mediaAssetId), $mediaStorage, false);
    }

    /**
     * Stream media as a download attachment.
     */
    public function download(string $mediaAssetId, MediaAssetAuthorizationService $authorization, MediaStorageServiceInterface $mediaStorage): StreamedResponse
    {
        return $this->respond($authorization->authorize($mediaAssetId), $mediaStorage, true);
    }

    /**
     * Build a chunked response that closes the provider stream after use.
     */
    private function respond(MediaAsset $asset, MediaStorageServiceInterface $mediaStorage, bool $download): StreamedResponse
    {
        $stream = $mediaStorage->openReadStream($asset->id);
        $disposition = $download ? 'attachment' : 'inline';

        return response()->stream(function () use ($stream): void {
            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 8192);

                    if ($chunk === false) {
                        throw new RuntimeException('Media stream could not be read.');
                    }

                    echo $chunk;
                }
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $this->safeMimeType($asset->mime_type),
            'Content-Disposition' => sprintf('%s; filename=%s', $disposition, $this->safeFilename($asset->original_filename)),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Restrict response content types to a valid media-type token pair.
     */
    private function safeMimeType(string $mimeType): string
    {
        return preg_match('/^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/i', $mimeType) === 1
            ? $mimeType
            : 'application/octet-stream';
    }

    /**
     * Remove control characters and header-significant bytes from filenames.
     */
    private function safeFilename(string $filename): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '', $filename) ?: 'media.bin';

        return $safe === '.' || $safe === '..' ? 'media.bin' : $safe;
    }
}
