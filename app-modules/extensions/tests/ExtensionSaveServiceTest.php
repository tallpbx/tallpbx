<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Database\Eloquent\Collection;
use Modules\Extensions\Models\Extension;
use Modules\Extensions\Services\ExtensionSaveService;
use Modules\SipAccounts\Models\SipAccount;
use Modules\SipAccounts\Services\SipAccountServiceInterface;
use Modules\Voicemails\Models\Voicemail;

/*
 * Saving an extension writes four related records: the extension, its portal
 * users, its SIP account, and its voicemail. One transaction owns the whole
 * aggregate, so a failure in any step leaves no orphan rows behind.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->user = User::factory()->create(['enabled' => true]);
    $this->user->tenants()->attach($this->tenant->id);
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);
});

afterEach(function (): void {
    app(TenantManager::class)->clear();
});

it('rolls the whole save back when the SIP account step fails', function (): void {
    $this->app->instance(SipAccountServiceInterface::class, new class implements SipAccountServiceInterface
    {
        public function create(array $data): SipAccount
        {
            throw new RuntimeException('SIP account provisioning failed.');
        }

        public function update(SipAccount $account, array $data): SipAccount
        {
            throw new RuntimeException('SIP account provisioning failed.');
        }

        public function delete(SipAccount $account): void {}

        public function getByTenant(int $tenantId): Collection
        {
            return new Collection;
        }
    });

    expect(fn () => app(ExtensionSaveService::class)->save(
        data: ['tenant_id' => $this->tenant->id, 'extension_number' => '204'],
        userIds: [],
        password: 'secret123',
        voicemailEnabled: false,
        voicemailPassword: '',
    ))->toThrow(RuntimeException::class);

    expect(Extension::withoutGlobalScope('tenant')->where('extension_number', '204')->exists())->toBeFalse();
});

it('writes the extension, its users, its SIP account and its voicemail in one save', function (): void {
    $extension = app(ExtensionSaveService::class)->save(
        data: ['tenant_id' => $this->tenant->id, 'extension_number' => '205', 'enabled' => true],
        userIds: [$this->user->id],
        password: 'secret123',
        voicemailEnabled: true,
        voicemailPassword: '1234',
    );

    expect($extension->users)->toHaveCount(1)
        ->and(SipAccount::withoutGlobalScope('tenant')->where('extension_id', $extension->id)->exists())->toBeTrue()
        ->and(Voicemail::withoutGlobalScope('tenant')->where('voicemail_id', '205')->exists())->toBeTrue();
});

it('does not create an unauthenticated SIP account when no password is supplied', function (): void {
    $extension = app(ExtensionSaveService::class)->save(
        data: ['tenant_id' => $this->tenant->id, 'extension_number' => '206', 'enabled' => true],
        userIds: [],
        password: '',
        voicemailEnabled: false,
        voicemailPassword: '',
    );

    expect(SipAccount::withoutGlobalScope('tenant')->where('extension_id', $extension->id)->exists())->toBeFalse();
});

it('updates an existing extension and its voicemail through the same owner', function (): void {
    $existing = Extension::factory()->create([
        'tenant_id' => $this->tenant->id,
        'extension_number' => '207',
        'enabled' => true,
    ]);

    $saved = app(ExtensionSaveService::class)->save(
        data: ['tenant_id' => $this->tenant->id, 'extension_number' => '207', 'display_name' => 'Renamed', 'enabled' => true],
        userIds: [],
        password: '',
        voicemailEnabled: true,
        voicemailPassword: '4321',
        existing: $existing,
    );

    expect($saved->id)->toBe($existing->id)
        ->and($saved->display_name)->toBe('Renamed')
        ->and(Voicemail::withoutGlobalScope('tenant')->where('voicemail_id', '207')->value('password'))->toBe('4321');
});
