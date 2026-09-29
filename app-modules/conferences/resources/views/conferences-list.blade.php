<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.conferences_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.conferences.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_conference') }}
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
                        <th>{{ __('admin.conference_name') }}</th>
                        <th>{{ __('admin.conference_profile') }}</th>
                        <th>{{ __('admin.conference_pin') }}</th>
                        <th>{{ __('admin.conference_max_members') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($conferences as $conference)
                        <tr>
                            <td class="font-medium">{{ $conference->name }}</td>
                            <td>
                                <span class="badge badge-ghost">{{ $conference->profile }}</span>
                            </td>
                            <td>
                                @if ($conference->pin)
                                    <span class="font-mono text-sm">{{ $conference->pin }}</span>
                                @else
                                    <span class="text-base-content/50">—</span>
                                @endif
                            </td>
                            <td>{{ $conference->max_members }}</td>
                            <td>
                                @if ($conference->enabled)
                                    <span class="badge badge-success badge-sm">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <x-icon-button icon="heroicon-o-pencil" :label="__('client.edit')" :href="route('panel.conferences.edit', $conference->id)" />
                                    <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$conference->name" wire:click="confirmConferenceDeletion('{{ $conference->id }}')" class="text-error" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_conferences_found') }}
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
            :title="__('admin.modal_delete_conference_title')"
            :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_conference_confirm')"
            confirm-action="deleteConference('{{ $pendingDeletionId }}')"
            cancel-action="cancelConferenceDeletion"
        />
    @endif
</div>
