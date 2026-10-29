{{-- TAB 5: DNS CREDENTIALS VAULT --}}
@if ($activeTab === 'dnsvault')
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4 space-y-4">
                <h2 class="text-base font-bold text-base-content">Stored DNS Provider Credentials</h2>
                <div class="overflow-x-auto">
                    <table class="table table-zebra table-sm w-full">
                        <thead>
                            <tr>
                                <th>{{ __('client.name') }}</th>
                                <th>{{ __('client.provider') }}</th>
                                <th>{{ __('client.created_at') }}</th>
                                <th class="text-right">{{ __('client.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->dnsCredentials as $cred)
                                <tr>
                                    <td class="font-medium">{{ $cred->name }}</td>
                                    <td>
                                        <span class="badge badge-outline badge-sm">{{ ucfirst($cred->provider) }}</span>
                                    </td>
                                    <td class="text-xs text-base-content/60">{{ $cred->created_at?->diffForHumans() }}</td>
                                    <td class="text-right">
                                        <button type="button"
                                                wire:click="confirmDeleteDnsCredential({{ $cred->id }})"
                                                class="btn btn-ghost btn-xs text-error">
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center py-6 text-base-content/60">
                                        No DNS credentials stored in vault yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-4 space-y-4">
                <h2 class="text-base font-bold text-base-content">Add Cloudflare API Token</h2>
                <form wire:submit="createDnsCredential" class="space-y-3">
                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">Credential Name</span>
                        </label>
                        <input type="text" wire:model="new_dns_name" placeholder="e.g. Cloudflare Production" class="input input-sm input-bordered w-full" required />
                        <x-form-error name="new_dns_name" />
                    </div>

                    <div class="form-control">
                        <label class="label justify-start gap-2">
                            <span class="label-text font-medium">Cloudflare API Token</span>
                            <x-tooltip tip="API Token with Zone:DNS:Edit permissions" position="top">
                                <x-heroicon-o-information-circle class="w-4 h-4 cursor-help opacity-50" />
                            </x-tooltip>
                        </label>
                        <input type="password" wire:model="new_dns_api_token" placeholder="••••••••••••••••" class="input input-sm input-bordered w-full font-mono" required />
                        <x-form-error name="new_dns_api_token" />
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="btn btn-primary btn-sm w-full gap-2">
                            <x-heroicon-o-key class="w-4 h-4" />
                            Save to Vault
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif

