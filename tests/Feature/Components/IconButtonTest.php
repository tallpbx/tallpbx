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
        $labels = require base_path("lang/{$locale}/client.php");

        expect($labels)->toHaveKeys(['delete', 'edit', 'view']);
    }
});
