<div>
    <x-page-header :title="__('admin.email_templates_title')" :tooltip="__('admin.page_info_tooltip')">
        <x-slot:actions>
            <a href="{{ route('panel.email-templates.create') }}" wire:navigate class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_email_template') }}
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
                        <th>{{ __('admin.email_template_name') }}</th>
                        <th>{{ __('admin.email_template_subject') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($templates as $template)
                        <tr>
                            <td class="font-medium">{{ $template->name }}</td>
                            <td class="text-sm">{{ $template->subject }}</td>
                            <td>
                                <div class="flex gap-2">
                                    <x-icon-button icon="heroicon-o-pencil" :label="__('client.edit')" :href="route('panel.email-templates.edit', $template->id)" />
                                    <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$template->name" wire:click="confirmTemplateDeletion('{{ $template->id }}')" class="text-error" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_email_templates_found') }}
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
            :title="__('admin.modal_delete_email_template_title')"
            :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_email_template_confirm')"
            confirm-action="deleteTemplate()"
            cancel-action="cancelTemplateDeletion"
        />
    @endif
</div>
