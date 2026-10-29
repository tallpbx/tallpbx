<?php

declare(strict_types=1);

use App\Services\PermissionService;

beforeEach(function (): void {
    $this->service = app(PermissionService::class);
    $this->service->reset();
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

it('computes a deterministic registry hash incorporating names and descriptions', function (): void {
    $this->service->register('extensions', ['extensions.view' => 'View extensions']);
    $hash1 = $this->service->registryHash();

    $expected1 = sha1((string) json_encode([
        'extensions.view' => [
            'module' => 'extensions',
            'description' => 'View extensions',
        ],
    ]));

    expect($hash1)->toBe($expected1);

    $this->service->register('admin', ['admin.view' => 'View admin']);
    $hash2 = $this->service->registryHash();

    $expected2 = sha1((string) json_encode([
        'admin.view' => [
            'module' => 'admin',
            'description' => 'View admin',
        ],
        'extensions.view' => [
            'module' => 'extensions',
            'description' => 'View extensions',
        ],
    ]));

    expect($hash2)->not->toBe($hash1)
        ->and($hash2)->toBe($expected2);
});

it('returns the exact same registry hash before and after syncToDatabase across multiple modules', function (): void {
    $this->service->register('extensions', [
        'extensions.view' => 'View extensions',
        'extensions.create' => 'Create extensions',
    ]);
    $this->service->register('admin', [
        'panel.dashboard.view' => 'View panel dashboard',
    ]);
    $this->service->register('ivr', [
        'ivr.view' => 'View IVR menus',
    ]);

    $hashBefore = $this->service->registryHash();

    $this->service->syncToDatabase();

    $hashAfter = $this->service->registryHash();

    expect($hashBefore)->toBe($hashAfter)
        ->and($hashBefore)->toBeString()
        ->and(strlen($hashBefore))->toBe(40);
});

it('changes registry hash when a permission description is updated', function (): void {
    $this->service->register('extensions', [
        'extensions.view' => 'Initial description',
    ]);
    $hash1 = $this->service->registryHash();

    $this->service->register('extensions', [
        'extensions.view' => 'Updated description',
    ]);
    $hash2 = $this->service->registryHash();

    expect($hash1)->not->toBe($hash2);
});

it('produces identical registry hash regardless of registration order', function (): void {
    $serviceA = new PermissionService;
    $serviceA->register('moduleA', ['a.perm' => 'A Desc']);
    $serviceA->register('moduleB', ['b.perm' => 'B Desc']);

    $serviceB = new PermissionService;
    $serviceB->register('moduleB', ['b.perm' => 'B Desc']);
    $serviceB->register('moduleA', ['a.perm' => 'A Desc']);

    expect($serviceA->registryHash())->toBe($serviceB->registryHash());
});

it('memoizes all() in memory and invalidates on register or reset', function (): void {
    $this->service->register('extensions', ['extensions.view']);
    $first = $this->service->all();

    expect($this->service->all())->toBe($first);

    $this->service->register('extensions', ['extensions.create']);
    $second = $this->service->all();

    expect($second)->toHaveCount(2)
        ->and($second)->toContain('extensions.view')
        ->and($second)->toContain('extensions.create');

    $this->service->reset();
    expect($this->service->all())->toBeEmpty();
});
