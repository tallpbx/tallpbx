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

    /**
     * Inject the email template service used by this component.
     */
    public function boot(EmailTemplateService $templateService): void
    {
        $this->templateService = $templateService;
    }

    /**
     * Load the template list when the page opens.
     */
    public function mount(): void
    {
        $this->load();
    }

    /**
     * Fetch templates ordered by name. Administrators see every
     * tenant's templates; tenant users only see their own.
     */
    private function load(): void
    {
        $query = $this->isAdminGuard()
            ? EmailTemplate::withoutGlobalScope('tenant')
            : EmailTemplate::query();

        $this->templates = $query->orderBy('name')->get();
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
