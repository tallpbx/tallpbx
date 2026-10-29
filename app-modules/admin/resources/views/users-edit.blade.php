<div>
    <x-page-header :title="$this->isEdit ? 'Edit User' : 'Create User'" />

    <div class="card bg-base-100 border border-base-300 max-w-2xl">
        <form wire:submit="save" class="card-body">
            {{-- Name --}}
            <div class="form-control w-full">
                <label for="name" class="label justify-start gap-2 pb-1">
                    <span class="label-text font-medium">{{ __('admin.name') }}</span>
                </label>
                <input type="text" id="name" wire:model="name"
                       class="input input-bordered w-full" placeholder="Full name" required />
                <x-form-error name="name" />
            </div>

            {{-- Email --}}
            <div class="form-control w-full mt-4">
                <label for="email" class="label justify-start gap-2 pb-1">
                    <span class="label-text font-medium">{{ __('admin.email') }}</span>
                </label>
                <input type="email" id="email" wire:model="email"
                       class="input input-bordered w-full" placeholder="email@example.com" required />
                <x-form-error name="email" />
            </div>

            {{-- Password --}}
            <div class="form-control w-full mt-4">
                <label for="password" class="label justify-start gap-2 pb-1">
                    <span class="label-text font-medium">
                        {{ $this->isEdit ? 'New Password' : 'Password' }}
                    </span>
                    <span class="label-text-alt text-base-content/60">
                        {{ $this->isEdit ? 'Leave blank to keep current' : 'Minimum 8 characters' }}
                    </span>
                </label>
                <input type="password" id="password" wire:model="password"
                       autocomplete="new-password"
                       class="input input-bordered w-full {{ $errors->has('password') ? 'input-error' : '' }}"
                       placeholder="{{ $this->isEdit ? 'Leave blank to keep current password' : 'Minimum 8 characters' }}" />
                <p class="text-xs text-base-content/60 mt-1">
                    {{ $this->isEdit ? 'Leave blank to keep current password, or enter at least 8 characters to set a new one.' : 'Must be at least 8 characters.' }}
                </p>
                <x-form-error name="password" />
            </div>

            {{-- Tenant Memberships --}}
            <div class="form-control w-full mt-6">
                <label class="label justify-start gap-2 pb-1">
                    <span class="label-text font-medium">{{ __('admin.tenants') }}</span>
                </label>

                @php
                    $selectedTenantIdStrings = array_map('strval', $selectedTenantIds);
                    $selectedTenants = $tenants->filter(
                        fn ($tenant): bool => in_array((string) $tenant->id, $selectedTenantIdStrings, true),
                    );
                @endphp

                <div
                    x-data="{ open: false, query: '' }"
                    x-on:click.outside="open = false"
                    class="relative"
                >
                    <button
                        type="button"
                        class="input input-bordered flex min-h-12 h-auto w-full items-center justify-between gap-3 py-2 text-left"
                        x-on:click="open = ! open"
                        x-bind:aria-expanded="open"
                    >
                        <span class="flex flex-wrap gap-2">
                            @forelse ($selectedTenants as $tenant)
                                <span wire:key="selected-tenant-{{ $tenant->id }}" class="badge badge-primary badge-outline">
                                    {{ $tenant->name }}
                                </span>
                            @empty
                                <span class="text-base-content/50">Select tenants...</span>
                            @endforelse
                        </span>
                        <x-heroicon-o-chevron-down class="h-4 w-4 shrink-0 text-base-content/60" />
                    </button>

                    <div
                        x-cloak
                        x-show="open"
                        class="absolute z-20 mt-2 w-full rounded-box border border-base-300 bg-base-100 shadow-xl"
                    >
                        <div class="border-b border-base-300 p-3">
                            <input
                                type="search"
                                x-model="query"
                                class="input input-bordered input-sm w-full"
                                placeholder="Search tenants..."
                                x-on:keydown.escape.stop="open = false"
                            />
                        </div>

                        <div class="max-h-64 overflow-y-auto">
                            @forelse ($tenants as $tenant)
                                <label
                                    wire:key="user-tenant-{{ $tenant->id }}"
                                    x-show="@js(strtolower($tenant->name.' '.$tenant->slug)).includes(query.toLowerCase())"
                                    class="flex items-center gap-3 p-3 cursor-pointer hover:bg-base-200"
                                >
                                    <input type="checkbox" class="checkbox checkbox-sm" wire:model="selectedTenantIds" value="{{ $tenant->id }}" />
                                    <span class="flex flex-col">
                                        <span class="font-medium">{{ $tenant->name }}</span>
                                        <span class="text-xs text-base-content/60">{{ $tenant->slug }}</span>
                                    </span>
                                </label>
                            @empty
                                <div class="p-3 text-xs text-base-content/60">No tenants available.</div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <x-form-error name="selectedTenantIds" />
                <x-form-error name="selectedTenantIds.*" />
            </div>

            {{-- Group Assignments --}}
            <div class="form-control w-full mt-6">
                <label class="label justify-start gap-2 pb-1">
                    <span class="label-text font-medium">{{ __('admin.groups') }}</span>
                </label>

                <div class="rounded-box border border-base-300 divide-y divide-base-300">
                    @forelse ($groups as $group)
                        @php
                            $groupTenant = $group->relationLoaded('tenant') ? $group->getRelation('tenant') : null;
                        @endphp
                        <label wire:key="user-group-{{ $group->id }}" class="flex items-center gap-3 p-3 cursor-pointer hover:bg-base-200">
                            <input type="checkbox" class="checkbox checkbox-sm" wire:model="selectedGroupIds" value="{{ $group->id }}" />
                            <span class="flex flex-col">
                                <span class="font-medium">{{ $group->name }}</span>
                                <span class="text-xs text-base-content/60">
                                    {{ $groupTenant ? 'Tenant: '.$groupTenant->name : 'System group' }}
                                </span>
                            </span>
                        </label>
                    @empty
                        <div class="p-3 text-xs text-base-content/60">No groups available.</div>
                    @endforelse
                </div>

                <x-form-error name="selectedGroupIds" />
                <x-form-error name="selectedGroupIds.*" />
            </div>

            {{-- Actions --}}
            <div class="flex gap-3 mt-6">
                <x-form-submit-button icon="heroicon-o-check">
                    {{ $this->isEdit ? 'Update User' : 'Create User' }}
                </x-form-submit-button>
                <a href="{{ route('panel.users.index') }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
            </div>
        </form>
    </div>
</div>
