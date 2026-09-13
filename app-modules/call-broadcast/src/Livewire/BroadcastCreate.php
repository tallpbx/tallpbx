<?php

declare(strict_types=1);

namespace Modules\CallBroadcast\Livewire;

use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\CallBroadcast\Services\CallBroadcastService;

#[Layout('layouts.app')]
class BroadcastCreate extends Component
{
    /** @var Collection<int, Tenant> */
    public Collection $tenants;

    public ?int $tenantId = null;

    public string $name = '';

    public string $phoneNumbers = '';

    private CallBroadcastService $broadcastService;

    public function boot(CallBroadcastService $broadcastService): void
    {
        $this->broadcastService = $broadcastService;
    }

    public function mount(): void
    {
        $this->tenants = Tenant::orderBy('name')->get();
    }

    public function save(): void
    {
        $this->validate($this->rules());

        $numbers = $this->parsePhoneNumbers();

        if ($this->getErrorBag()->has('phoneNumbers') || $numbers === []) {
            // An empty recipient list would produce a sendable draft that
            // completes instantly with no calls.
            if ($numbers === []) {
                $this->addError('phoneNumbers', 'Add at least one phone number.');
            }

            return;
        }

        $this->broadcastService->createWithRecipients([
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'status' => 'draft',
        ], $numbers);

        $this->redirect(route('panel.call-broadcast.index'));
    }

    /**
     * Normalize the phone numbers textarea into validated, deduplicated numbers.
     *
     * @return array<int, string>
     */
    private function parsePhoneNumbers(): array
    {
        $numbers = [];

        foreach (preg_split('/\R/', $this->phoneNumbers) ?: [] as $line) {
            $number = trim($line);

            if ($number === '') {
                continue;
            }

            if (preg_match('/^\+?[0-9]{6,15}$/', $number) !== 1) {
                $this->addError('phoneNumbers', 'Each phone number must be 6-15 digits, optionally prefixed with +.');

                continue;
            }

            $numbers[$number] = true; // dedup
        }

        return array_keys($numbers);
    }

    public function rules(): array
    {
        return [
            'tenantId' => ['required', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'phoneNumbers' => ['nullable', 'string'],
        ];
    }

    public function render(): View
    {
        return view('call-broadcast::broadcast-create');
    }
}
