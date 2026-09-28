<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\Extensions\Models\Extension;
use Modules\HotDesking\Livewire\HotDeskingEdit;
use Modules\HotDesking\Livewire\HotDeskingList;
use Modules\HotDesking\Models\HotDeskSession;

beforeEach(function (): void {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();

    $this->extUser = Extension::factory()->create([
        'tenant_id' => $this->tenant->id,
        'extension_number' => '201',
        'display_name' => 'Alice User',
    ]);

    $this->extDesk = Extension::factory()->create([
        'tenant_id' => $this->tenant->id,
        'extension_number' => '104',
        'display_name' => 'Desk 4',
    ]);
});

it('renders the hot desking list component with active sessions', function (): void {
    HotDeskSession::factory()->create([
        'tenant_id' => $this->tenant->id,
        'extension_id' => $this->extUser->id,
        'device_extension_id' => $this->extDesk->id,
        'is_active' => true,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(HotDeskingList::class)
        ->assertOk()
        ->assertSee('Hot Desking')
        ->assertSee('201')
        ->assertSee('104');
});

it('can end an active session from the list component', function (): void {
    $session = HotDeskSession::factory()->create([
        'tenant_id' => $this->tenant->id,
        'extension_id' => $this->extUser->id,
        'device_extension_id' => $this->extDesk->id,
        'is_active' => true,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(HotDeskingList::class)
        ->call('endSession', $session->id)
        ->assertDispatched('session-ended');

    expect($session->fresh()->is_active)->toBeFalse()
        ->and($session->fresh()->logout_at)->not->toBeNull();
});

it('can delete a hot desking record from the list component', function (): void {
    $session = HotDeskSession::factory()->create([
        'tenant_id' => $this->tenant->id,
        'extension_id' => $this->extUser->id,
        'device_extension_id' => $this->extDesk->id,
        'is_active' => false,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(HotDeskingList::class)
        ->call('deleteSession', $session->id)
        ->assertDispatched('session-deleted');

    expect(HotDeskSession::withoutGlobalScope('tenant')->find($session->id))->toBeNull();
});

it('renders the hot desking edit component and can create a session', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(HotDeskingEdit::class)
        ->assertOk()
        ->set('tenantId', $this->tenant->id)
        ->set('extensionId', $this->extUser->id)
        ->set('deviceExtensionId', $this->extDesk->id)
        ->set('description', 'Test Session')
        ->call('save')
        ->assertRedirect(route('panel.hot-desking.index'));

    expect(HotDeskSession::withoutGlobalScope('tenant')->where('extension_id', $this->extUser->id)->active()->exists())->toBeTrue();
});

it('validates that visiting extension and desk extension must be different', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(HotDeskingEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('extensionId', $this->extUser->id)
        ->set('deviceExtensionId', $this->extUser->id)
        ->call('save')
        ->assertHasErrors(['extensionId' => 'different']);
});
