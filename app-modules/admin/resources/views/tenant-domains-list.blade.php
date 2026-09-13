<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.tenant_domains_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.tenant-domains.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.add_domain') }}
        </a>
    </div>

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
                        <tr>
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
                                    <a href="{{ route('panel.tenant-domains.edit', $domain->id) }}"
                                       class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button wire:click="confirmDomainDeletion({{ $domain->id }})"
                                            class="btn btn-ghost btn-xs text-error">
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
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
