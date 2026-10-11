<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('renders nothing when no operational message or session feedback exists', function (): void {
    $html = Blade::render('<x-operational-toast />');

    expect($html)->toBe('');
});

it('renders success toast with 5-second auto-dismiss and polite aria-live', function (): void {
    $html = Blade::render(
        '<x-operational-toast :message="$message" :type="$type" />',
        ['message' => 'Firewall rule enabled', 'type' => 'success']
    );

    expect($html)
        ->toContain('Firewall rule enabled')
        ->toContain('alert-success')
        ->toContain('toast-top toast-end')
        ->toContain('role="status"')
        ->toContain('aria-live="polite"')
        ->toContain('role="alert"')
        ->toContain('wire:click="dismissFeedback"')
        ->toContain('@click="dismiss()"')
        ->toContain('toast-progress')
        ->toContain('5000')
        ->toContain('true');
});

it('renders error toast with persistent behavior and assertive aria-live', function (): void {
    $html = Blade::render(
        '<x-operational-toast :message="$message" :type="$type" />',
        ['message' => 'Failed to apply rule', 'type' => 'error']
    );

    expect($html)
        ->toContain('Failed to apply rule')
        ->toContain('alert-error')
        ->toContain('role="alert"')
        ->toContain('aria-live="assertive"')
        ->toContain('wire:click="dismissFeedback"')
        ->not->toContain('toast-progress')
        ->toContain('false'); // isAutoDismiss = false
});

it('renders custom timeout when provided', function (): void {
    $html = Blade::render(
        '<x-operational-toast :message="$message" :type="$type" :timeout="3000" />',
        ['message' => 'Quick notice', 'type' => 'info']
    );

    expect($html)
        ->toContain('Quick notice')
        ->toContain('3000')
        ->toContain('toast-progress')
        ->toContain('true');
});

it('renders flashed session status with auto-dismiss and progress line', function (): void {
    session()->flash('status', 'Settings saved successfully');

    $html = Blade::render('<x-operational-toast />');

    expect($html)
        ->toContain('Settings saved successfully')
        ->toContain('alert-success')
        ->toContain('toast-progress')
        ->toContain('5000')
        ->toContain('true');
});

it('renders flashed session error with persistent behavior and no progress line', function (): void {
    session()->flash('error', 'Operation rejected by system');

    $html = Blade::render('<x-operational-toast />');

    expect($html)
        ->toContain('Operation rejected by system')
        ->toContain('alert-error')
        ->toContain('aria-live="assertive"')
        ->not->toContain('toast-progress')
        ->toContain('false');
});
