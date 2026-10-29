<div>
    <x-page-header :title="__('admin.acl_title')" :tooltip="__('admin.acl_tooltip')">
        <x-slot:actions>
            <a href="{{ route('panel.acl.create') }}" wire:navigate class="btn btn-primary btn-sm">
                <x-heroicon-o-plus class="w-4 h-4" />
                {{ __('admin.create_acl') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.name') }}</th>
                        <th>{{ __('admin.acl_action') }}</th>
                        <th>{{ __('admin.acl_nodes') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rules as $rule)
                        <tr>
                            <td class="font-medium">{{ $rule->name }}</td>
                            <td>
                                @if($rule->action === 'allow')
                                    <span class="badge badge-success">{{ __('admin.acl_action_allow') }}</span>
                                @else
                                    <span class="badge badge-error">{{ __('admin.acl_action_deny') }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-ghost">{{ $rule->nodes_count }} {{ __('admin.acl_nodes') }}</span>
                            </td>
                            <td>
                                @if($rule->enabled)
                                    <span class="badge badge-success">{{ __('client.active') }}</span>
                                @else
                                    <span class="badge badge-ghost">{{ __('client.inactive') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <x-icon-button icon="heroicon-o-pencil" :label="__('client.edit')" :href="route('panel.acl.edit', $rule->id)" />
                                    <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$rule->name" wire:click="confirmRuleDeletion('{{ $rule->id }}')" class="text-error" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_acls_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if ($pendingDeletionId !== null)<x-confirmation-modal :open="true" :title="__('admin.modal_delete_acl_title')" :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])" :confirm-label="__('admin.modal_delete_acl_confirm')" confirm-action="deleteRule('{{ $pendingDeletionId }}')" cancel-action="cancelRuleDeletion" />@endif
</div>
