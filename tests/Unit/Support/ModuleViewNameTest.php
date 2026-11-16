<?php

declare(strict_types=1);

use App\Support\ModuleViewName;
use Modules\Bridges\Livewire\BridgesEdit;

/*
 * Blade view names resolve from the component's file path. The same mapping
 * was copied into BaseEditComponent and BaseListComponent; this pins the one
 * shared implementation so the two lists can never disagree.
 */

it('resolves a module view name from the class', function (): void {
    expect(ModuleViewName::for(BridgesEdit::class))->toBe('bridges::bridges-edit');
});

it('resolves a legacy livewire path with its prefix', function (): void {
    $path = '/var/www/tallpbx/modules/bridges/src/Http/Livewire/BridgesEdit.php';

    expect(ModuleViewName::fromPath($path))->toBe('bridges::livewire.bridges-edit');
});

it('resolves an app-modules livewire path without the legacy prefix', function (): void {
    $path = '/var/www/tallpbx/app-modules/call-detail-records/src/Livewire/CdrList.php';

    expect(ModuleViewName::fromPath($path))->toBe('call-detail-records::cdr-list');
});

it('converts PascalCase class names to kebab-case view names', function (): void {
    $path = '/var/www/tallpbx/app-modules/sip-accounts/src/Livewire/SipAccountsEdit.php';

    expect(ModuleViewName::fromPath($path))->toBe('sip-accounts::sip-accounts-edit');
});
