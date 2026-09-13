<?php

declare(strict_types=1);

namespace Modules\PinNumbers\Livewire;

use App\Support\BaseListComponent;
use Illuminate\Database\Eloquent\Collection;
use Modules\PinNumbers\Models\PinNumber;

/**
 * Livewire component for listing PIN numbers.
 * Displays all PIN numbers with their status and provides delete capability.
 */
class PinNumbersList extends BaseListComponent
{
    /** @var Collection<int, PinNumber> */
    public Collection $pinNumbers;

    public ?string $pendingDeletionId = null;

    public string $pendingDeletionName = '';

    public function mount(): void
    {
        $this->loadPinNumbers();
    }

    /**
     * Fetch all PIN numbers ordered by pin_number.
     */
    private function loadPinNumbers(): void
    {
        $this->pinNumbers = PinNumber::withoutGlobalScope('tenant')
            ->orderBy('pin_number')
            ->get();
    }

    /**
     * Delete a PIN number and refresh the list.
     */
    public function deletePinNumber(string $pinId): void
    {
        $pin = PinNumber::withoutGlobalScope('tenant')->findOrFail($pinId);
        $pin->delete();
        $this->cancelPinNumberDeletion();
        $this->loadPinNumbers();
        $this->showSuccess('PIN number deleted.');
        $this->dispatch('pin-number-deleted');
    }

    /** Open the shared deletion confirmation for a PIN number. */
    public function confirmPinNumberDeletion(string $pinId): void
    {
        $pin = PinNumber::withoutGlobalScope('tenant')->findOrFail($pinId);
        $this->pendingDeletionId = $pin->id;
        $this->pendingDeletionName = $pin->pin_number;
    }

    /** Close the PIN-number deletion confirmation. */
    public function cancelPinNumberDeletion(): void
    {
        $this->pendingDeletionId = null;
        $this->pendingDeletionName = '';
    }
}
