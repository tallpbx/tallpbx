<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.sip_profiles_title') }}</h2><x-tooltip :tip="__('admin.sip_profile_header_tooltip')" align="start" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.sip-profiles.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_sip_profile') }}
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
                        <th>{{ __('admin.profile_name') }}</th>
                        <th>{{ __('admin.profile_description') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($profiles as $profile)
                        <tr>
                            <td class="font-medium">{{ $profile->name }}</td>
                            <td class="text-base-content/60">{{ $profile->description }}</td>
                            <td>
                                @if($profile->enabled)
                                    <span class="badge badge-success">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-ghost">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ route('panel.sip-profiles.edit', $profile->id) }}"
                                       class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button wire:click="confirmProfileDeletion('{{ $profile->id }}')"
                                            class="btn btn-ghost btn-xs text-error">
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_sip_profiles_found') }}
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
            :title="__('admin.modal_delete_sip_profile_title')"
            :message="__('admin.modal_delete_generic_question', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_sip_profile_confirm')"
            confirm-action="deleteProfile('{{ $pendingDeletionId }}')"
            cancel-action="cancelProfileDeletion"
        />
    @endif
</div>
