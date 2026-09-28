<?php

declare(strict_types=1);

it('maps module references to kebab-case module names', function (): void {
    $names = $this->extractModuleNames(
        'use Modules\\Extensions\\Models\\Extension; new Modules\\SipAccounts\\Models\\SipAccount();',
    );

    expect($names)->toBe(['extensions', 'sip-accounts']);
});

it('knows which modules are installed in the checkout', function (): void {
    expect($this->moduleIsInstalled('extensions'))->toBeTrue()
        ->and($this->moduleIsInstalled('definitely-not-a-module'))->toBeFalse();
});

it('skips the current test when its module is not installed', function (): void {
    $this->skipWhenModuleUninstalled('definitely-not-a-module');

    $this->fail('The test should have been skipped.');
});
