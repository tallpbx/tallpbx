<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Models\Group;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use App\Services\UserServiceInterface;
use App\Support\PanelActor;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Livewire component for creating and editing users.
 *
 * Admin-only access. Handles user CRUD with tenant
 * and group assignments.
 */
#[Layout('layouts.app')]
class UsersEdit extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    #[Locked]
    public ?int $userId = null;

    /** @var array<int, int> */
    public array $selectedTenantIds = [];

    /** @var array<int, string> */
    public array $selectedGroupIds = [];

    /** @var Collection<int, Tenant> */
    public Collection $tenants;

    /** @var Collection<int, Group> */
    public Collection $groups;

    private UserServiceInterface $userService;

    /**
     * Map actions on this component to required ability groups.
     *
     * @return array<string, array<int, array<int, string>>>
     */
    public static function livewireActionAbilities(): array
    {
        return [
            '' => [['admin.users.view']],
            'save' => [['admin.users.create', 'admin.users.update']],
            'getIsEditProperty' => [['admin.users.view']],
        ];
    }

    /**
     * Inject the user service used by this component.
     */
    public function boot(UserServiceInterface $userService): void
    {
        $this->userService = $userService;
    }

    /**
     * Mount the component in create or edit mode.
     */
    public function mount(?int $userId = null): void
    {
        $this->tenants = Tenant::orderBy('name')->get();
        $this->groups = Group::with('tenant')
            ->orderBy('tenant_id')
            ->orderBy('name')
            ->get();

        if ($userId !== null) {
            $this->userId = $userId;
            $user = User::with(['tenants', 'groups'])->findOrFail($userId);

            if (! PanelActor::isAdmin()) {
                $activeTenantId = app(TenantManager::class)->getTenantId();
                if ($activeTenantId === null || ! $user->tenants->contains('id', (int) $activeTenantId)) {
                    abort(403, 'Cross-tenant access denied.');
                }
            }

            $this->name = $user->name;
            $this->email = $user->email;
            $this->selectedTenantIds = $user->tenants->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $this->selectedGroupIds = $user->groups->pluck('id')->all();
        }
    }

    /**
     * Return whether we're in edit mode.
     */
    public function getIsEditProperty(): bool
    {
        return $this->userId !== null;
    }

    /**
     * Save the user — creates or updates.
     */
    public function save(): void
    {
        abort_unless(PanelActor::can($this->userId === null ? 'admin.users.create' : 'admin.users.update'), 403);

        if (! PanelActor::isAdmin()) {
            $activeTenantId = app(TenantManager::class)->getTenantId();
            if ($activeTenantId === null || array_diff(array_map('intval', $this->selectedTenantIds), [(int) $activeTenantId]) !== []) {
                abort(403, 'Cross-tenant access denied.');
            }

            if ($this->userId !== null) {
                $targetUser = User::findOrFail($this->userId);
                if (! $targetUser->tenants()->where('tenants.id', $activeTenantId)->exists()) {
                    abort(403, 'Cross-tenant access denied.');
                }
            }
        }

        $this->validate($this->rules());

        $data = [
            'name' => $this->name,
            'email' => $this->email,
        ];

        if ($this->password !== '') {
            $data['password'] = $this->password;
        }

        $user = $this->userId !== null
            ? User::findOrFail($this->userId)
            : null;

        $this->userService->saveWithAssignments($user, $data, $this->selectedTenantIds, $this->selectedGroupIds);

        $this->redirect(route('panel.users.index'));
    }

    /**
     * Validation rules with unique email constraint.
     */
    protected function rules(): array
    {
        $uniqueRule = Rule::unique('users', 'email')
            ->ignore($this->userId);

        $passwordRule = $this->userId === null
            ? ['required', 'string', 'min:8']
            : ['nullable', 'string', 'min:8'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', $uniqueRule],
            'password' => $passwordRule,
            'selectedTenantIds' => ['array'],
            'selectedTenantIds.*' => ['integer', 'distinct', 'exists:tenants,id'],
            'selectedGroupIds' => ['array'],
            'selectedGroupIds.*' => ['string', 'distinct', 'exists:groups,id'],
        ];
    }

    /**
     * Render the user edit form.
     */
    public function render(): View
    {
        return view('admin::users-edit');
    }
}
