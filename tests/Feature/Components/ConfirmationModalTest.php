<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('renders the modal without a typed input when no required text is set', function (): void {
    $html = Blade::render(
        '<x-confirmation-modal :open="true" title="Delete Setting?" message="This cannot be undone." confirm-label="Delete Setting" confirm-action="deleteSetting()" cancel-action="cancelSettingDeletion" />'
    );

    expect($html)
        ->toContain('Delete Setting?')
        ->not->toContain('type="text"');
});

it('renders a typed input and disabled confirm binding when required text is set', function (): void {
    $html = Blade::render(
        '<x-confirmation-modal :open="true" title="Delete Tenant?" message="Type the tenant name to confirm." confirm-label="Delete Tenant" confirm-action="deleteTenant()" cancel-action="cancelTenantDeletion" :required-text="$requiredText" />',
        ['requiredText' => 'Acme Corp']
    );

    expect($html)
        ->toContain('type="text"')
        ->toContain('x-model="typed"')
        ->toContain('wire:model="confirmTypedInput"')
        ->toContain("typed.trim() !== 'Acme Corp'");
});

it('escapes required text containing a single quote', function (): void {
    $html = Blade::render(
        '<x-confirmation-modal :open="true" title="Delete Tenant?" message="Type the tenant name to confirm." confirm-label="Delete Tenant" confirm-action="deleteTenant()" cancel-action="cancelTenantDeletion" :required-text="$requiredText" />',
        ['requiredText' => "O'Brien Corp"]
    );

    expect($html)
        ->toContain("'O\\u0027Brien Corp'")
        ->not->toContain("'O'Brien Corp'");
});
