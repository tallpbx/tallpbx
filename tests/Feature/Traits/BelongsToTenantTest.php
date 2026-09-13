<?php

use App\Models\Tenant;
use App\Services\TenantManager;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();

    // Build a test model that uses the trait
    $this->tenantModel = new class extends Model
    {
        use BelongsToTenant;

        protected $table = 'tenant_user';

        public $timestamps = false;
    };
});

it('applies global scope when tenant is set', function () {
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);

    $query = $this->tenantModel->newQuery()->toSql();
    expect($query)->toContain('where');
    expect($query)->toContain('tenant_id');
});

it('throws RuntimeException when no tenant context is set', function () {
    app(TenantManager::class)->clear();

    expect(fn () => $this->tenantModel->newQuery()->toSql())
        ->toThrow(RuntimeException::class, 'Tenant scope applied but no tenant context is set.');
});
