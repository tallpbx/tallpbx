<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('renders standard submit button with default save label and loading state', function (): void {
    $html = Blade::render('<x-form-submit-button />');

    expect($html)
        ->toContain('<button')
        ->toContain('type="submit"')
        ->toContain('btn btn-primary btn-sm')
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:target="save"')
        ->toContain('loading loading-spinner loading-xs')
        ->toContain('wire:loading.remove')
        ->toContain(__('admin.save'))
        ->toContain(__('admin.saving'));
});

it('renders custom slot content when provided', function (): void {
    $html = Blade::render('<x-form-submit-button>Create Extension</x-form-submit-button>');

    expect($html)
        ->toContain('Create Extension')
        ->toContain(__('admin.saving'))
        ->toContain('loading loading-spinner loading-xs');
});

it('renders custom busy text when provided', function (): void {
    $html = Blade::render('<x-form-submit-button busy-text="Updating...">Update</x-form-submit-button>');

    expect($html)
        ->toContain('Update')
        ->toContain('Updating...')
        ->not->toContain(__('admin.saving'));
});

it('supports custom target method', function (): void {
    $html = Blade::render('<x-form-submit-button target="updateProfile">Update Profile</x-form-submit-button>');

    expect($html)
        ->toContain('wire:target="updateProfile"')
        ->not->toContain('wire:target="save"');
});

it('omits wire:target when target is false or empty', function (): void {
    $html = Blade::render('<x-form-submit-button :target="false">Submit</x-form-submit-button>');

    expect($html)
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:loading.remove')
        ->toContain('wire:loading')
        ->not->toContain('wire:target');
});

it('disables loading behavior when loading prop is false', function (): void {
    $html = Blade::render('<x-form-submit-button :loading="false">Submit</x-form-submit-button>');

    expect($html)
        ->toContain('<button')
        ->toContain('Submit')
        ->not->toContain('wire:loading.attr="disabled"')
        ->not->toContain('loading-spinner');
});

it('renders icon when icon prop is provided', function (): void {
    $html = Blade::render('<x-form-submit-button icon="heroicon-o-check">Save</x-form-submit-button>');

    expect($html)
        ->toContain('<svg')
        ->toContain('Save');
});

it('merges custom css classes properly', function (): void {
    $html = Blade::render('<x-form-submit-button class="w-full btn-secondary">Submit</x-form-submit-button>');

    expect($html)
        ->toContain('btn btn-primary btn-sm w-full btn-secondary');
});

it('every locale defines the saving and save translations', function (): void {
    foreach (['en', 'es', 'fr'] as $locale) {
        $admin = require base_path("lang/{$locale}/admin.php");
        expect($admin)->toHaveKeys(['save', 'saving']);
    }
});
