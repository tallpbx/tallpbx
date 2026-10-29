<div>
    <x-page-header :title="__('admin.sip_accounts_title')" :tooltip="__('admin.page_info_tooltip')">
        <x-slot:actions>
            <a href="{{ route('panel.sip-accounts.create') }}" wire:navigate class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_sip_account') }}
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
                        <th>{{ __('admin.sip_username') }}</th>
                        <th>{{ __('admin.sip_type') }}</th>
                        <th>{{ __('admin.sip_domain') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($accounts as $account)
                        <tr wire:key="{{ $account->id }}"
                            wire:loading.class="opacity-40 pointer-events-none"
                            wire:target="confirmAccountDeletion('{{ $account->id }}')">
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
                                    <x-icon-button icon="heroicon-o-pencil" :label="__('client.edit')" :href="route('panel.sip-accounts.edit', $account->id)" />
                                    <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$account->auth_username" wire:click="confirmAccountDeletion('{{ $account->id }}')" class="text-error" />
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

    @if (method_exists($accounts, 'links'))
        <div class="mt-4">
            {{ $accounts->links() }}
        </div>
    @endif

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
