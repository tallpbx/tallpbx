<?php

declare(strict_types=1);

use App\Services\PermissionService;

beforeEach(function (): void {
    $this->service = app(PermissionService::class);
});

it('registers permissions for a module', function (): void {
    $this->service->register('extensions', [
        'extensions.view',
        'extensions.create',
    ]);

    $all = $this->service->all();

    expect($all)->toHaveCount(2);
    expect($all)->toContain('extensions.view');
    expect($all)->toContain('extensions.create');
});

it('merges multiple registrations for the same module', function (): void {
    $this->service->register('extensions', ['extensions.view']);
    $this->service->register('extensions', ['extensions.create', 'extensions.update']);

    $all = $this->service->all();

    expect($all)->toHaveCount(3);
    expect($all)->toContain('extensions.view');
    expect($all)->toContain('extensions.create');
    expect($all)->toContain('extensions.update');
});

it('deduplicates permissions', function (): void {
    $this->service->register('extensions', [
        'extensions.view',
        'extensions.view',
        'extensions.create',
    ]);

    $all = $this->service->all();

    expect($all)->toHaveCount(2);
});

it('groups permissions by module', function (): void {
    $this->service->register('extensions', ['extensions.view', 'extensions.create']);
    $this->service->register('admin', ['panel.dashboard.view']);

    $grouped = $this->service->grouped();

    expect($grouped)->toHaveKeys(['extensions', 'admin']);
    expect($grouped['extensions'])->toHaveCount(2);
    expect($grouped['admin'])->toHaveCount(1);
    expect($grouped['extensions'][0])->toHaveKeys(['name', 'description']);
});

it('returns empty array when no permissions registered', function (): void {
    expect($this->service->all())->toBe([]);
    expect($this->service->grouped())->toBe([]);
});
