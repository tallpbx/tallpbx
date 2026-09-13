<?php

declare(strict_types=1);

namespace Modules\Admin\Livewire;

use App\Models\Admin;
use App\Models\Group;
use App\Support\Concerns\HasOperationalFeedback;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Livewire component for creating and editing administrator accounts.
 *
 * Provides fields for name, email, password, enabled status, and system groups.
 * Protects system stability by preventing administrators from locking themselves out.
 */
#[Layout('layouts.app')]
class AdminsEdit extends Component
{
    use HasOperationalFeedback;

    public ?int $adminId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $enabled = true;

    /** @var array<int, string> */
    public array $selectedGroupIds = [];

    /** @var Collection<int, Group> */
    public Collection $systemGroups;

    public function mount(?int $adminId = null): void
    {
        $this->systemGroups = Group::whereNull('tenant_id')
            ->orderBy('name')
            ->get();

        $this->adminId = $adminId;

        if ($this->adminId !== null) {
            $admin = Admin::with('groups')->findOrFail($this->adminId);

            $this->name = $admin->name;
            $this->email = $admin->email;
            $this->enabled = (bool) $admin->enabled;
            $this->selectedGroupIds = $admin->groups->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        } else {
            // Default new admins to Super Administrators if group exists
            $superAdmin = $this->systemGroups->firstWhere('name', 'Super Administrators');
            if ($superAdmin !== null) {
                $this->selectedGroupIds = [(string) $superAdmin->id];
            }
        }
    }

    public function save(): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('admins', 'email')->ignore($this->adminId),
            ],
            'enabled' => ['boolean'],
            'selectedGroupIds' => ['array'],
            'selectedGroupIds.*' => ['string', Rule::exists('groups', 'id')->whereNull('tenant_id')],
        ];

        if ($this->adminId === null) {
            $rules['password'] = ['required', 'string', 'min:8', 'confirmed'];
        } elseif ($this->password !== '') {
            $rules['password'] = ['string', 'min:8', 'confirmed'];
        }

        $this->validate($rules);

        // Safety check: Cannot disable self
        $currentAdmin = Auth::guard('admin')->user();
        if ($this->adminId !== null && $currentAdmin !== null && $currentAdmin->id === $this->adminId && ! $this->enabled) {
            $this->addError('enabled', __('admin.admin_cannot_disable_self'));

            return;
        }

        if ($this->adminId === null) {
            $admin = Admin::create([
                'name' => trim($this->name),
                'email' => trim($this->email),
                'password' => $this->password,
                'enabled' => $this->enabled,
            ]);

            $admin->groups()->sync($this->selectedGroupIds);

            session()->flash('operationalMessage', __('admin.admin_created_success'));
            session()->flash('operationalMessageType', 'success');
        } else {
            $admin = Admin::findOrFail($this->adminId);

            $data = [
                'name' => trim($this->name),
                'email' => trim($this->email),
                'enabled' => $this->enabled,
            ];

            if ($this->password !== '') {
                $data['password'] = $this->password;
            }

            $admin->update($data);
            $admin->groups()->sync($this->selectedGroupIds);

            session()->flash('operationalMessage', __('admin.admin_updated_success'));
            session()->flash('operationalMessageType', 'success');
        }

        $this->redirect(route('panel.admins.index'), navigate: true);
    }

    public function render(): View
    {
        return view('admin::admins-edit');
    }
}
