<?php

declare(strict_types=1);

namespace Modules\XmlCdr\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\XmlCdr\Models\Cdr;

/**
 * Livewire component showing one call detail record in full.
 */
#[Layout('layouts.app')]
class CdrDetail extends Component
{
    public ?string $cdrId = null;

    /**
     * Remember which call detail record to display.
     */
    public function mount(string $cdrId): void
    {
        $this->cdrId = $cdrId;
    }

    /**
     * Load the record by id and render the detail view.
     */
    public function render(): View
    {
        $cdr = Cdr::withoutGlobalScope('tenant')->findOrFail($this->cdrId);

        return view('xml-cdr::cdr-detail', ['cdr' => $cdr]);
    }
}
