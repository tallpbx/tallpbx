<div>
    <x-page-header :title="__('admin.tenant_domains_title')" :tooltip="__('admin.page_info_tooltip')">
        <x-slot:actions>
            <a href="{{ route('panel.tenant-domains.create') }}" wire:navigate class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.add_domain') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    @if ($operationalMessage !== null)
        <x-inline-alert :type="$operationalMessageType" :title="null" class="mb-4">{{ $operationalMessage }}</x-inline-alert>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.domain') }}</th>
                        <th>{{ __('admin.tenants') }}</th>
                        <th>{{ __('admin.purpose') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($domains as $domain)
                        <tr wire:key="{{ $domain->id }}"
                            wire:loading.class="opacity-40 pointer-events-none"
                            wire:target="confirmDomainDeletion({{ $domain->id }})">
                            <td class="font-medium">{{ $domain->domain }}</td>
                            <td>{{ $domain->tenant->name ?? '—' }}</td>
                            <td>
                                <span class="badge badge-ghost">{{ $domain->purpose }}</span>
                            </td>
                            <td>
                                @if($domain->enabled)
                                    <span class="badge badge-success">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-error">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <x-icon-button icon="heroicon-o-pencil" :label="__('client.edit')" :href="route('panel.tenant-domains.edit', $domain->id)" />
                                    <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$domain->domain" wire:click="confirmDomainDeletion({{ $domain->id }})" class="text-error" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_domains_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($pendingDeletionId !== null)
        <x-confirmation-modal
            :open="true"
            :title="__('admin.modal_delete_tenant_domain_title')"
            :message="__('admin.modal_delete_domain_message', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_tenant_domain_confirm')"
            confirm-action="deleteDomain()"
            cancel-action="cancelDomainDeletion"
            :error="$deleteError"
            :error-title="__('admin.modal_delete_domain_error_title')"
        />
    @endif
</div>
