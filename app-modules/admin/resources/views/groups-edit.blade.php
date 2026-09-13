<div class="card bg-base-100 border border-base-300 max-w-2xl">
        <form wire:submit="save" class="card-body">
            {{-- Save bar at top --}}
            <div class="flex items-center justify-between pb-4 mb-4 border-b border-base-300">
                <h3 class="font-medium">{{ $this->isEdit ? 'Edit Group' : 'Create Group' }}</h3>
                <div class="flex gap-3">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <x-heroicon-o-check class="w-4 h-4" />
                        {{ $this->isEdit ? 'Update Group' : 'Create Group' }}
                    </button>
                    <a href="{{ route('panel.groups.index') }}" class="btn btn-ghost btn-sm">{{ __('client.cancel') }}</a>
                </div>
            </div>
            {{-- Name --}}
            <div class="form-control w-full">
                <label for="name" class="label justify-start gap-2 pb-1">
                    <span class="label-text font-medium">Name</span>
                </label>
                <input type="text"
                       id="name"
                       wire:model="name"
                       class="input input-bordered w-full"
                       placeholder="Enter group name"
                       required />
                @error('name')
                    <span class="label-text-alt text-error">{{ $message }}</span>
                @enderror
            </div>

            {{-- Description --}}
            <div class="form-control w-full mt-4">
                <label for="description" class="label justify-start gap-2 pb-1">
                    <span class="label-text font-medium">Description</span>
                </label>
                <textarea id="description"
                          wire:model="description"
                          class="textarea textarea-bordered w-full"
                          rows="3"
                          placeholder="Optional description"></textarea>
                @error('description')
                    <span class="label-text-alt text-error">{{ $message }}</span>
                @enderror
            </div>

            {{-- Tenant --}}
            <div class="form-control w-full mt-4">
                <label for="tenantId" class="label justify-start gap-2 pb-1">
                    <span class="label-text font-medium">Tenant</span>
                </label>
                <select id="tenantId"
                        wire:model="tenantId"
                        class="select select-bordered w-full">
                    <option value="">-- System Group (no tenant) --</option>
                    @foreach($tenants as $tenant)
                        <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                    @endforeach
                </select>
                @error('tenantId')
                    <span class="label-text-alt text-error">{{ $message }}</span>
                @enderror
            </div>

            {{-- Permissions table (FusionPBX-style: flat table grouped by module) --}}
            <div class="form-control w-full mt-6">
                <label class="label justify-between pb-1">
                    <span class="label-text font-medium">Permissions</span>
                    <span class="label-text-alt text-base-content/50">
                        {{ count($selectedPermissions) }} selected
                    </span>
                </label>

                @if(empty($permissionGroups))
                    <div class="text-sm text-base-content/40 py-4">
                        No permissions available.
                        <a href="{{ route('panel.permissions.index') }}" class="link link-primary">Sync permissions first</a>.
                    </div>
                @else
                    <div class="border border-base-300 rounded-lg overflow-hidden">
                        <table class="table table-zebra table-sm">
                            <tbody>
                                @foreach($permissionGroups as $module => $perms)
                                    <tr class="bg-base-200">
                                        <td colspan="2" class="text-xs font-semibold uppercase tracking-wider text-base-content/50">
                                            {{ $module }}
                                        </td>
                                    </tr>
                                    @foreach($perms as $perm)
                                        <tr>
                                            <td class="w-8">
                                                <input type="checkbox"
                                                       wire:model="selectedPermissions"
                                                       value="{{ $perm['id'] }}"
                                                       class="checkbox checkbox-xs checkbox-primary" />
                                            </td>
                                            <td class="font-mono text-sm">{{ $perm['name'] }}</td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </form>
    </div>
