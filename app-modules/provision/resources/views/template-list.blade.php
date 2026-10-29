<div>
    <x-page-header :title="__('admin.provision_templates_title')" :tooltip="__('admin.page_info_tooltip')">
        <x-slot:actions>
            <a href="{{ route('panel.provision.templates.create') }}" wire:navigate class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_template') }}
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
                        <th>{{ __('admin.template_name') }}</th>
                        <th>{{ __('admin.template_vendor') }}</th>
                        <th>{{ __('admin.template_model') }}</th>
                        <th>{{ __('admin.template_file_path') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($templates as $t)
                        <tr>
                            <td class="font-medium">{{ $t->name }}</td>
                            <td><span class="badge badge-ghost">{{ $t->vendor }}</span></td>
                            <td>{{ $t->model ?? '—' }}</td>
                            <td><span class="font-mono text-xs">{{ $t->file_path }}</span></td>
                            <td>
                                @if($t->enabled)
                                    <span class="badge badge-success badge-sm">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <x-icon-button icon="heroicon-o-pencil" :label="__('client.edit')" :href="route('panel.provision.templates.edit', $t->id)" />
                                    <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$t->name" wire:click="confirmTemplateDeletion('{{ $t->id }}')" class="text-error" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_templates_found') }}
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
            :title="__('admin.modal_delete_template_title')"
            :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_template_confirm')"
            confirm-action="deleteTemplate()"
            cancel-action="cancelTemplateDeletion"
        />
    @endif
</div>
