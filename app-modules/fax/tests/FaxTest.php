<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Fax\Livewire\FaxInbox;
use Modules\Fax\Livewire\FaxSend;
use Modules\Fax\Models\FaxInbox as FaxInboxModel;
use Modules\Fax\Services\FaxServiceInterface;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the fax inbox list component', function () {
    FaxInboxModel::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(FaxInbox::class)
        ->assertOk()
        ->assertSee('Fax Inbox')
        ->assertViewHas('faxes', function ($faxes) {
            return $faxes->count() === 2;
        });
});

it('renders the fax send form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(FaxSend::class)
        ->assertOk()
        ->assertSee('Send');
});

it('sends a fax', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(FaxSend::class)
        ->set('tenantId', $tenant->id)
        ->set('faxNumber', '+15551234567')
        ->set('document', UploadedFile::fake()->createWithContent('document.pdf', "%PDF-1.4\n%%EOF\n"))
        ->call('send')
        ->assertRedirect(route('panel.fax.index'));

    $this->assertDatabaseHas('fax_queue', [
        'fax_number' => '+15551234567',
    ]);
});

it('deletes an inbox fax', function () {
    $fax = FaxInboxModel::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(FaxInbox::class)
        ->call('confirmFaxDeletion', $fax->id)
        ->assertSet('pendingDeletionId', $fax->id)
        ->call('deleteFax')
        ->assertSet('operationalMessage', 'Fax deleted.')
        ->assertDispatched('fax-deleted');

    $this->assertModelMissing($fax);
});

it('opens the shared confirmation modal before deleting an inbox fax', function (): void {
    $fax = FaxInboxModel::factory()->create(['caller_id' => '+15551234567']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(FaxInbox::class)
        ->call('confirmFaxDeletion', $fax->id)
        ->assertSet('pendingDeletionId', $fax->id)
        ->assertSet('pendingDeletionName', '+15551234567')
        ->assertSee('Delete Fax?');
});

it('renders the confirmation modal inside the single component root element', function (): void {
    // Livewire morphs only the first root element on updates, so a modal rendered
    // outside the root div would silently disappear in the browser.
    $fax = FaxInboxModel::factory()->create();

    $html = view('fax::fax-inbox', [
        'faxes' => FaxInboxModel::withoutGlobalScope('tenant')->get(),
        'operationalMessage' => null,
        'operationalMessageType' => null,
        'pendingDeletionId' => $fax->id,
        'pendingDeletionName' => '+15551234567',
        'deleteError' => null,
    ])->render();

    $doc = new DOMDocument;
    @$doc->loadHTML('<?xml encoding="utf-8"?><div id="root-wrapper">'.$html.'</div>');
    $wrapper = $doc->getElementById('root-wrapper');
    $rootElements = collect($wrapper->childNodes)
        ->filter(fn ($node) => $node instanceof DOMElement)
        ->values();

    expect($rootElements)->toHaveCount(1);
});

it('opens the confirmation modal safely when a fax has no caller ID', function (): void {
    $fax = FaxInboxModel::factory()->create(['caller_id' => null]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(FaxInbox::class)
        ->call('confirmFaxDeletion', $fax->id)
        ->assertSet('pendingDeletionId', $fax->id)
        ->assertSet('pendingDeletionName', '')
        ->assertSee('Delete Fax?');
});

it('keeps the confirmation open with a safe error when fax deletion fails', function (): void {
    $fax = FaxInboxModel::factory()->create();
    $service = Mockery::mock(FaxServiceInterface::class);
    $service->shouldReceive('deleteInbox')->once()->andThrow(new RuntimeException('media retained'));
    app()->instance(FaxServiceInterface::class, $service);

    Livewire::actingAs($this->admin, 'admin')
        ->test(FaxInbox::class)
        ->call('confirmFaxDeletion', $fax->id)
        ->call('deleteFax')
        ->assertSet('deleteError', 'Fax could not be deleted. Please try again.')
        ->assertSet('pendingDeletionId', $fax->id);

    $this->assertModelExists($fax);
});

it('shows empty state when fax inbox is empty', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(FaxInbox::class)
        ->assertSee('No faxes found');
});
