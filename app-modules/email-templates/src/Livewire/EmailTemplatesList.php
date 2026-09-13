<?php

declare(strict_types=1);

namespace Modules\EmailTemplates\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\EmailTemplates\Models\EmailTemplate;
use Modules\EmailTemplates\Services\EmailTemplateService;

/**
 * Admin page listing all email notification templates.
 *
 * Displays a table of templates with name and subject. Admins can create, edit, or delete.
 */
class EmailTemplatesList extends BaseListComponent
{
    /** @var Collection<int, EmailTemplate> */
    public Collection $templates;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    private EmailTemplateService $templateService;

    public function boot(EmailTemplateService $templateService): void
    {
        $this->templateService = $templateService;
    }

    public function mount(): void
    {
        $this->load();
    }

    private function load(): void
    {
        $this->templates = EmailTemplate::withoutGlobalScope('tenant')
            ->orderBy('name')
            ->get();
    }

    /** Open the shared confirmation modal for an email template. */
    public function confirmTemplateDeletion(string $id): void
    {
        $template = EmailTemplate::withoutGlobalScope('tenant')->findOrFail($id);
        $this->pendingDeletionId = $template->id;
        $this->pendingDeletionName = $template->name;
    }

    /** Close the email-template confirmation modal without deleting anything. */
    public function cancelTemplateDeletion(): void
    {
        $this->reset('pendingDeletionId', 'pendingDeletionName');
    }

    /** Delete the email template that the user confirmed. */
    public function deleteTemplate(): void
    {
        $template = EmailTemplate::withoutGlobalScope('tenant')->findOrFail($this->pendingDeletionId);
        $this->templateService->delete($template);
        $this->cancelTemplateDeletion();
        $this->load();
        $this->showSuccess('Email template deleted.');
        $this->dispatch('notify', message: __('admin.deleted'));
    }
}
