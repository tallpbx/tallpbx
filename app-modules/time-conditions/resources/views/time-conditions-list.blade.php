<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.time_conditions_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
        <a href="{{ route('panel.time-conditions.create') }}" class="btn btn-primary btn-sm">
            <x-heroicon-o-plus class="w-4 h-4" />
            {{ __('admin.create_time_condition') }}
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
                        <th>{{ __('admin.time_condition_name') }}</th>
                        <th>{{ __('admin.time_condition_weekdays') }}</th>
                        <th>{{ __('admin.time_condition_hours') }}</th>
                        <th>{{ __('admin.time_condition_match_destination') }}</th>
                        <th>{{ __('client.status') }}</th>
                        <th>{{ __('client.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($timeConditions as $condition)
                        <tr>
                            <td class="font-medium">{{ $condition->name }}</td>
                            <td><span class="badge badge-ghost">{{ $condition->weekdays }}</span></td>
                            <td>{{ $condition->start_time }} - {{ $condition->end_time }}</td>
                            <td>
                                @if ($condition->destination_on_match)
                                    <span class="font-mono text-sm">{{ $condition->destination_on_match }}</span>
                                @else
                                    <span class="text-base-content/50">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($condition->enabled)
                                    <span class="badge badge-success badge-sm">{{ __('admin.enabled') }}</span>
                                @else
                                    <span class="badge badge-ghost badge-sm">{{ __('admin.disabled') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <a href="{{ route('panel.time-conditions.edit', $condition->id) }}" class="btn btn-ghost btn-xs">
                                        <x-heroicon-o-pencil class="w-4 h-4" />
                                    </a>
                                    <button
                                        wire:click="confirmTimeConditionDeletion('{{ $condition->id }}')"
                                        class="btn btn-ghost btn-xs text-error"
                                    >
                                        <x-heroicon-o-trash class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_time_conditions_found') }}
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
            :title="__('admin.modal_delete_time_condition_title')"
            :message="__('admin.modal_delete_generic_statement', ['name' => $pendingDeletionName])"
            :confirm-label="__('admin.modal_delete_time_condition_confirm')"
            confirm-action="deleteTimeCondition('{{ $pendingDeletionId }}')"
            cancel-action="cancelTimeConditionDeletion"
        />
    @endif
</div>
