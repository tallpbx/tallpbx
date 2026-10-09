<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;

it('renders a labeled button with the accessible name', function (): void {
    $html = Blade::render('<x-icon-button icon="heroicon-o-trash" label="Delete recording" />');

    expect($html)
        ->toContain('<button')
        ->toContain('aria-label="Delete recording"')
        ->toContain('btn btn-ghost btn-xs');
});

it('renders a labeled navigation link when href is given', function (): void {
    $html = Blade::render('<x-icon-button icon="heroicon-o-pencil" label="Edit recording" href="/panel/recordings/1/edit" />');

    expect($html)
        ->toContain('<a')
        ->toContain('href="/panel/recordings/1/edit"')
        ->toContain('wire:navigate')
        ->toContain('aria-label="Edit recording"');
});

it('renders a loading spinner and wire directives when loading-target is specified', function (): void {
    $html = Blade::render('<x-icon-button icon="heroicon-o-trash" label="Delete recording" loading-target="deleteRecord(1)" />');

    expect($html)
        ->toContain('<button')
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:target="deleteRecord(1)"')
        ->toContain('loading loading-spinner loading-xs')
        ->toContain('wire:loading.remove');
});

it('infers loading target from wire:click by default', function (): void {
    $html = Blade::render('<x-icon-button icon="heroicon-o-trash" label="Delete recording" wire:click="deleteRecord(1)" />');

    expect($html)
        ->toContain('<button')
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:target="deleteRecord(1)"')
        ->toContain('loading loading-spinner loading-xs')
        ->toContain('wire:loading.remove');
});

it('disables loading behavior when loading prop is explicitly false', function (): void {
    $html = Blade::render('<x-icon-button icon="heroicon-o-trash" label="Delete recording" wire:click="deleteRecord(1)" :loading="false" />');

    expect($html)
        ->toContain('<button')
        ->not->toContain('wire:loading.attr="disabled"')
        ->not->toContain('loading-spinner');
});

it('refuses to render without an accessible label', function (): void {
    // Blade wraps component exceptions in ViewException (twice in this
    // rendering path), so walk the chain to the component's real contract.
    try {
        Blade::render('<x-icon-button icon="heroicon-o-trash" />');
        $this->fail('Expected an InvalidArgumentException to be thrown');
    } catch (ViewException $e) {
        $root = $e;
        while ($root->getPrevious() !== null) {
            $root = $root->getPrevious();
        }

        expect($root)->toBeInstanceOf(InvalidArgumentException::class);
        expect($e->getMessage())->toContain('requires a non-empty label');
    }
});

it('every locale defines the client action labels', function (): void {
    foreach (['en', 'es', 'fr'] as $locale) {
        // These keys back the aria-labels on list action buttons and the
        // play/download media links the labeling sweep introduced.
        $labels = require base_path("lang/{$locale}/client.php");

        expect($labels)->toHaveKeys(['delete', 'edit', 'view', 'play', 'download']);
    }
});

it('every locale defines the admin action labels', function (): void {
    foreach (['en', 'es', 'fr'] as $locale) {
        // These keys back the unknown-value fallbacks and the password
        // visibility toggles the labeling sweep introduced.
        $labels = require base_path("lang/{$locale}/admin.php");

        expect($labels)->toHaveKeys(['unknown', 'password_visibility']);
    }
});
