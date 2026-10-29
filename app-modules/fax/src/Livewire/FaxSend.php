<?php

declare(strict_types=1);

namespace Modules\Fax\Livewire;

use App\Support\BaseEditComponent;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Fax\Services\FaxServiceInterface;

/**
 * Livewire component for uploading a document and sending it as a fax.
 */
#[Layout('layouts.app')]
class FaxSend extends BaseEditComponent
{
    use WithFileUploads;

    public string $faxNumber = '';

    /** @var TemporaryUploadedFile|null */
    public $document = null;

    private FaxServiceInterface $faxService;

    /**
     * Inject the fax service used by this component.
     */
    public function boot(FaxServiceInterface $faxService): void
    {
        $this->faxService = $faxService;
    }

    /**
     * Load the tenant options when the page opens.
     */
    public function mount(): void
    {
        $this->loadTenants();
    }

    /**
     * Validate the form, queue the uploaded document as a fax, and
     * return to the fax page.
     */
    public function send(): void
    {
        $this->validate($this->rules());

        try {
            $this->faxService->send([
                'tenant_id' => $this->resolveTenantId(),
                'fax_number' => $this->faxNumber,
                'source_path' => $this->document->getRealPath(),
                'original_filename' => $this->document->getClientOriginalName(),
            ]);
        } catch (\RuntimeException $exception) {
            $this->showError('Fax could not be queued. Check the document and storage configuration, then try again.');

            return;
        }

        $this->redirect(route('panel.fax.index'));
    }

    /**
     * Validation rules for the fax sending form.
     */
    public function rules(): array
    {
        return [
            'tenantId' => ['required', 'integer', 'exists:tenants,id'],
            'faxNumber' => ['required', 'string', 'max:255'],
            'document' => ['required', 'file', 'mimetypes:application/pdf', 'max:10240'],
        ];
    }

    /**
     * Render the fax sending form.
     */
    public function render(): View
    {
        return view('fax::fax-send');
    }
}
