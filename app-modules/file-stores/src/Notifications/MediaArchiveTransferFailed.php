<?php

declare(strict_types=1);

namespace Modules\FileStores\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\FileStores\Models\MediaAsset;

/**
 * Alerts enabled administrators about a retained failed archive spool.
 */
class MediaArchiveTransferFailed extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create an alert for one media asset failure episode.
     */
    public function __construct(private readonly MediaAsset $asset) {}

    /**
     * Deliver operational alerts through the existing database notifications UI.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Return only non-sensitive operational metadata.
     *
     * @return array{title: string, message: string, media_asset_id: string, category: string, original_filename: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Media archive transfer failed',
            'message' => 'A media archive could not be transferred. Its local spool has been retained for recovery.',
            'media_asset_id' => $this->asset->id,
            'category' => $this->asset->category->value,
            'original_filename' => $this->asset->original_filename,
        ];
    }
}
