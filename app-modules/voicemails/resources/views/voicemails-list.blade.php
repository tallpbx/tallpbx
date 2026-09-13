<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.voicemails_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.voicemails.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_voicemail') }}
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
                        <th>{{ __('admin.voicemail_mailbox') }}</th>
                        <th>{{ __('admin.name') }}</th>
                        <th>{{ __('admin.voicemail_email') }}</th>
                        <th>{{ __('admin.voicemail_pin') }}</th>
                        <th>{{ __('admin.voicemail_forward_email') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($voicemails as $voicemail)
                        <tr>
                            <td class="font-medium">{{ $voicemail->voicemail_id }}</td>
                            <td>{{ $voicemail->name ?? '—' }}</td>
                            <td>{{ $voicemail->email ?? '—' }}</td>
                            <td>
                                @if($voicemail->require_password)
                                    <span class="badge badge-info">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-ghost">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                @if($voicemail->forward_to_email)
                                    <span class="badge badge-success">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-ghost">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                @if($voicemail->enabled)
                                    <span class="badge badge-success">{{ __('client.active') }}</span>
                                @else
                                    <span class="badge badge-ghost">{{ __('client.inactive') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ route('panel.voicemails.edit', $voicemail->id) }}"
                                       class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button wire:click="confirmVoicemailDeletion('{{ $voicemail->id }}')"
                                            class="btn btn-ghost btn-xs text-error">
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_voicemails_found') }}
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
            :title="__('admin.modal_delete_voicemail_mailbox_title')"
            :message="__('admin.modal_delete_voicemail_mailbox_message', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_voicemail_mailbox_confirm')"
            confirm-action="deleteVoicemail('{{ $pendingDeletionId }}')"
            cancel-action="cancelVoicemailDeletion"
            :error="$deleteError"
            :error-title="__('admin.modal_delete_voicemail_mailbox_error_title')"
        />
    @endif
</div>
