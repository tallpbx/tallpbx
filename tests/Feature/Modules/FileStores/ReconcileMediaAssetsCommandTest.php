<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Modules\FileStores\Console\ReconcileMediaAssets;

it('registers the media reconciliation command', function (): void {
    expect(Artisan::all())->toHaveKey('media:reconcile');
    expect(app(ReconcileMediaAssets::class)->getName())->toBe('media:reconcile');
});
