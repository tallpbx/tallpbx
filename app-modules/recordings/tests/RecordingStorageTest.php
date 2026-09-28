<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\MorphOne;
use Modules\ConferenceCenters\Models\ConferenceCenter;
use Modules\FileStores\Models\MediaAsset;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\MusicOnHold\Models\MusicOnHold;
use Modules\Recordings\Models\Recording;
use Modules\Voicemails\Models\Voicemail;

it('exposes a morph-one managed media asset for recordings', function (): void {
    expect((new Recording)->mediaAsset())->toBeInstanceOf(MorphOne::class)
        ->and((new Recording)->mediaAsset()->getRelated())->toBeInstanceOf(MediaAsset::class);
});

it('exposes one managed media asset for every local-only media owner', function (): void {
    foreach ([new MusicOnHold, new Voicemail, new IvrMenu, new ConferenceCenter] as $owner) {
        expect($owner->mediaAsset())
            ->toBeInstanceOf(MorphOne::class)
            ->and($owner->mediaAsset()->getRelated())->toBeInstanceOf(MediaAsset::class);
    }
});
