<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantServiceInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Rule as LivewireRule;
use Livewire\Component;

/**
 * Livewire component for creating and editing tenants.
 *
 * Admin-only access. Handles tenant CRUD with optional
 * primary user assignment. The primary user dropdown
 * shows all users in the system.
 */
#[Layout('layouts.app')]
class TenantsEdit extends Component
{
    #[LivewireRule('required|string|max:255')]
    public string $name = '';

    #[LivewireRule('required|string|max:255|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/')]
    public string $slug = '';

    public bool $enabled = true;

    public ?int $primaryUserId = null;

    public ?int $tenantId = null;

    /** @var Collection<int, User> */
    public Collection $users;

    private TenantServiceInterface $tenantService;

    public function boot(TenantServiceInterface $tenantService): void
    {
        $this->tenantService = $tenantService;
    }

    /**
     * Mount the component in create or edit mode.
     */
    public function mount(?int $tenantId = null): void
    {
        $this->users = User::orderBy('name')->get();

        if ($tenantId !== null) {
            $this->tenantId = $tenantId;
            $tenant = Tenant::findOrFail($tenantId);
            $this->name = $tenant->name;
            $this->slug = $tenant->slug;
            $this->enabled = $tenant->enabled;
            $this->primaryUserId = $tenant->primary_user_id;
        }
    }

    /**
     * Return whether we're in edit mode.
     */
    public function getIsEditProperty(): bool
    {
        return $this->tenantId !== null;
    }

    /**
     * Save the tenant — creates or updates.
     */
    public function save(): void
    {
        $this->validate($this->rules());

        $data = [
            'name' => $this->name,
            'slug' => $this->slug,
            'enabled' => $this->enabled,
            'primary_user_id' => $this->primaryUserId,
        ];

        if ($this->tenantId !== null) {
            $tenant = Tenant::findOrFail($this->tenantId);
            $this->tenantService->update($tenant, $data);
        } else {
            $this->tenantService->create($data);
        }

        $this->redirect(route('panel.tenants.index'));
    }

    /**
     * Validation rules with unique slug constraint.
     */
    protected function rules(): array
    {
        $slugRule = Rule::unique('tenants', 'slug')
            ->ignore($this->tenantId);

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugRule],
            'primaryUserId' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function render(): View
    {
        return view('admin::tenants-edit');
    }
}
