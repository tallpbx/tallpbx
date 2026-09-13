<?php

use App\Services\TenantManager;

beforeEach(function () {
    app()->singleton(TenantManager::class);
    $this->manager = app(TenantManager::class);
});

it('starts with no tenant', function () {
    expect($this->manager->hasTenant())->toBeFalse();
    expect($this->manager->getTenantId())->toBeNull();
});

it('can set and get tenant id', function () {
    $this->manager->setTenantId('42');

    expect($this->manager->getTenantId())->toBe('42');
    expect($this->manager->hasTenant())->toBeTrue();
});

it('can clear tenant id', function () {
    $this->manager->setTenantId('7');
    expect($this->manager->hasTenant())->toBeTrue();

    $this->manager->clear();
    expect($this->manager->hasTenant())->toBeFalse();
    expect($this->manager->getTenantId())->toBeNull();
});

it('can set tenant id to null', function () {
    $this->manager->setTenantId('99');
    $this->manager->setTenantId(null);

    expect($this->manager->hasTenant())->toBeFalse();
});

it('is a singleton', function () {
    $instance1 = app(TenantManager::class);
    $instance2 = app(TenantManager::class);

    expect($instance1)->toBe($instance2);
});
