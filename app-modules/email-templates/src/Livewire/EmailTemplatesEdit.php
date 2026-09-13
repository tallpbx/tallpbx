<?php

declare(strict_types=1);

namespace Modules\EmailTemplates\Livewire;

use App\Support\BaseEditComponent;
use Modules\EmailTemplates\Models\EmailTemplate;
use Modules\EmailTemplates\Services\EmailTemplateService;

/**
 * Create/edit form for email notification templates.
 *
 * Provides fields for name, subject, and body content with HTML textarea.
 */
class EmailTemplatesEdit extends BaseEditComponent
{
    public string $name = '';

    public string $subject = '';

    public string $body = '';

    public ?string $templateId = null;

    private EmailTemplateService $templateService;

    public function boot(EmailTemplateService $templateService): void
    {
        $this->templateService = $templateService;
    }

    public function mount(?string $templateId = null): void
    {
        $this->loadTenants();

        if ($templateId === null) {
            return;
        }

        $template = EmailTemplate::withoutGlobalScope('tenant')->findOrFail($templateId);
        // Tenant users may only open records of their active tenant.
        $this->assertCanAccessTenantRecord($template);
        $this->templateId = $template->id;
        $this->tenantId = $template->tenant_id;
        $this->name = $template->name;
        $this->subject = $template->subject;
        $this->body = $template->body;
    }

    public function getIsEditProperty(): bool
    {
        return $this->templateId !== null;
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'subject' => $this->subject,
            'body' => $this->body,
        ];

        if ($this->isEdit) {
            $template = EmailTemplate::withoutGlobalScope('tenant')->findOrFail($this->templateId);
            $this->templateService->update($template, $data);
        } else {
            $this->templateService->create($data);
        }

        $this->redirect(route('panel.email-templates.index'), navigate: true);
    }

    public function rules(): array
    {
        return [
            'tenantId' => 'required|exists:tenants,id',
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
        ];
    }
}
