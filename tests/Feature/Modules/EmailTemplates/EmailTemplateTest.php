<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\EmailTemplates\Livewire\EmailTemplatesEdit;
use Modules\EmailTemplates\Livewire\EmailTemplatesList;
use Modules\EmailTemplates\Models\EmailTemplate;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);
});

afterEach(function () {
    app(TenantManager::class)->setTenantId(null);
});

it('renders the list component', function () {
    EmailTemplate::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(EmailTemplatesList::class)
        ->assertOk()
        ->assertSee('Email Templates')
        ->assertViewHas('templates', fn ($items) => $items->count() === 2);
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(EmailTemplatesEdit::class)
        ->assertOk()
        ->assertSee('Create');
});

it('renders the edit form', function () {
    $template = EmailTemplate::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(EmailTemplatesEdit::class, ['templateId' => $template->id])
        ->assertOk()
        ->assertSee('Edit');
});

it('creates an email template', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(EmailTemplatesEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'Welcome Email')
        ->set('subject', 'Welcome to {{ $tenant }}')
        ->set('body', 'Hello {{ $user }}, welcome!')
        ->call('save')
        ->assertRedirect(route('panel.email-templates.index'));

    expect(EmailTemplate::count())->toBe(1);
});

it('validates required fields', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(EmailTemplatesEdit::class)
        ->call('save')
        ->assertHasErrors(['tenantId', 'name', 'subject', 'body']);
});

it('deletes an email template', function () {
    $template = EmailTemplate::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(EmailTemplatesList::class)
        ->call('confirmTemplateDeletion', $template->id)
        ->assertSet('pendingDeletionId', $template->id)
        ->call('deleteTemplate')
        ->assertSet('operationalMessage', 'Email template deleted.')
        ->assertOk();

    expect(EmailTemplate::count())->toBe(0);
});

it('opens the shared confirmation modal before deleting an email template', function (): void {
    $template = EmailTemplate::factory()->create(['name' => 'Welcome template']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(EmailTemplatesList::class)
        ->call('confirmTemplateDeletion', $template->id)
        ->assertSet('pendingDeletionId', $template->id)
        ->assertSet('pendingDeletionName', 'Welcome template')
        ->assertSee('Delete Email Template?');
});

it('shows empty state when no templates exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(EmailTemplatesList::class)
        ->assertSee('No templates found');
});
