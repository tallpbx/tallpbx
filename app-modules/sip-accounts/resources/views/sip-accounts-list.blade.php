<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.sip_accounts_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.sip-accounts.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_sip_account') }}
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
                        <th>{{ __('admin.sip_username') }}</th>
                        <th>{{ __('admin.sip_type') }}</th>
                        <th>{{ __('admin.sip_domain') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($accounts as $account)
                        <tr>
                            <td class="font-medium">{{ $account->auth_username }}</td>
                            <td>
                                <span class="badge badge-ghost">
                                    {{ match($account->identity_mode) {
                                        'global_username' => __('admin.sip_type_global'),
                                        'domain_username' => __('admin.sip_type_domain'),
                                        'hybrid' => __('admin.sip_type_hybrid'),
                                        default => $account->identity_mode
                                    } }}
                                </span>
                            </td>
                            <td class="text-base-content/60">
                                {{ $account->tenantDomain?->domain ?? '—' }}
                            </td>
                            <td>
                                @if($account->enabled)
                                    <span class="badge badge-success">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-ghost">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ route('panel.sip-accounts.edit', $account->id) }}"
                                       class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button wire:click="confirmAccountDeletion('{{ $account->id }}')"
                                            class="btn btn-ghost btn-xs text-error">
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_sip_accounts_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
       </div>
    </div>

    <div class="mt-4">
        {{ $accounts->links() }}
    </div>

    @if ($pendingDeletionId !== null)
        <x-confirmation-modal
            :open="true"
            :title="__('admin.modal_delete_sip_account_title')"
            :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_sip_account_confirm')"
            confirm-action="deleteAccount('{{ $pendingDeletionId }}')"
            cancel-action="cancelAccountDeletion"
        />
    @endif
</div>
