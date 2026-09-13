<?php

declare(strict_types=1);

namespace Modules\FileStores\Services;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Modules\FileStores\Enums\MediaAssetStatus;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Models\MediaAsset;

/**
 * Authorizes private media access without exposing inaccessible assets.
 */
class MediaAssetAuthorizationService
{
    /** @var array<string, string> */
    private const CategoryPermissions = [
        MediaCategory::VoicemailMessage->value => 'voicemail-messages.view',
        MediaCategory::CallRecording->value => 'call-recordings.view',
        MediaCategory::FaxInbound->value => 'fax.view',
        MediaCategory::FaxOutbound->value => 'fax.view',
        MediaCategory::Recording->value => 'recordings.view',
        MediaCategory::MusicOnHold->value => 'music-on-hold.view',
        MediaCategory::VoicemailGreeting->value => 'voicemails.view',
        MediaCategory::IvrGreeting->value => 'ivr-menus.view',
        MediaCategory::ConferenceGreeting->value => 'conference-centers.view',
    ];

    /**
     * Return an available asset only when the active panel actor may view it.
     */
    public function authorize(string $mediaAssetId): MediaAsset
    {
        $asset = MediaAsset::withoutGlobalScope('tenant')->find($mediaAssetId);

        if ($asset === null || $asset->status !== MediaAssetStatus::Available) {
            abort(404);
        }

        $permission = self::CategoryPermissions[$asset->category->value];
        $admin = Auth::guard('admin')->user();

        if ($admin instanceof Admin) {
            abort_unless($admin->hasPermission($permission), 403);

            return $asset;
        }

        $user = Auth::guard('web')->user();

        if (! $user instanceof User || ! $user->isInTenant($asset->tenant_id)) {
            abort(404);
        }

        abort_unless($user->hasPermission($permission), 403);

        return $asset;
    }
}
