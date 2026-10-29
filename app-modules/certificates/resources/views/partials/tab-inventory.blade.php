{{-- TAB 1: INVENTORY & SERVICES --}}
@if ($activeTab === 'inventory')
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body p-4 space-y-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative w-full sm:w-72">
                    <input type="text"
                           wire:model.live.debounce.300ms="search"
                           placeholder="Search certificates..."
                           class="input input-sm input-bordered w-full pr-8" />
                    <x-heroicon-o-magnifying-glass class="w-4 h-4 absolute right-2.5 top-2.5 text-base-content/40" />
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="switchTab('letsencrypt')" class="btn btn-primary btn-sm gap-1">
                        <x-heroicon-o-plus class="w-4 h-4" />
                        Issue Certificate
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="table table-zebra table-sm w-full">
                    <thead>
                        <tr>
                            <th>{{ __('admin.certificates_name_domain') }}</th>
                            <th>{{ __('client.type') }}</th>
                            <th>{{ __('admin.certificates_san_domains') }}</th>
                            <th>{{ __('admin.certificates_expires_in') }}</th>
                            <th>{{ __('admin.certificates_assigned_services') }}</th>
                            <th class="text-right">{{ __('client.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->certificates as $cert)
                            <tr>
                                <td>
                                    <div class="font-medium text-base-content">{{ $cert->name }}</div>
                                    <div class="text-xs font-mono text-base-content/70">{{ $cert->common_name }}</div>
                                </td>
                                <td>
                                    @if ($cert->type === \Modules\Certificates\Models\Certificate::TYPE_LETS_ENCRYPT)
                                        <span class="badge badge-primary badge-sm">Let's Encrypt</span>
                                    @elseif ($cert->type === \Modules\Certificates\Models\Certificate::TYPE_CUSTOM)
                                        <span class="badge badge-info badge-sm">Custom</span>
                                    @else
                                        <span class="badge badge-secondary badge-sm">Self-Signed</span>
                                    @endif
                                </td>
                                <td>
                                    @php $sans = $cert->san_domains ?? []; @endphp
                                    @if (count($sans) > 0)
                                        <x-tooltip :tip="implode(', ', $sans)" position="top">
                                            <span class="badge badge-ghost badge-sm cursor-help">{{ count($sans) }} SANs</span>
                                        </x-tooltip>
                                    @else
                                        <span class="text-xs text-base-content/40">None</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="{{ $cert->statusBadgeClass() }}">
                                        @if ($cert->isExpired())
                                            Expired
                                        @else
                                            {{ $cert->days_until_expiration }} days
                                        @endif
                                    </span>
                                </td>
                                <td>
                                    <div class="flex items-center gap-1">
                                        @if ($cert->is_default_web)
                                            <span class="badge badge-success badge-sm">Web HTTPS</span>
                                        @endif
                                        @if ($cert->is_default_telephony)
                                            <span class="badge badge-primary badge-sm">SIP / WSS</span>
                                        @endif
                                        @if (! $cert->is_default_web && ! $cert->is_default_telephony)
                                            <span class="badge badge-ghost badge-sm text-base-content/40">None</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-right">
                                    <div class="inline-flex items-center gap-1">
                                        <button type="button"
                                                id="assign-cert-{{ $cert->id }}"
                                                wire:click="openAssignModal({{ $cert->id }})"
                                                class="btn btn-ghost btn-xs"
                                                title="Assign Services">
                                            Deploy
                                        </button>
                                        <button type="button"
                                                wire:click="openDetailsModal({{ $cert->id }})"
                                                class="btn btn-ghost btn-xs"
                                                title="View Details">
                                            Details
                                        </button>
                                        @if ($cert->type === \Modules\Certificates\Models\Certificate::TYPE_LETS_ENCRYPT)
                                            <button type="button"
                                                    wire:click="renewCertificate({{ $cert->id }})"
                                                    wire:loading.attr="disabled"
                                                    class="btn btn-ghost btn-xs text-info"
                                                    title="Renew Now">
                                                Renew
                                            </button>
                                        @endif
                                        <button type="button"
                                                wire:click="confirmDeleteCertificate({{ $cert->id }})"
                                                @if ($cert->is_default_web || $cert->is_default_telephony) disabled @endif
                                                class="btn btn-ghost btn-xs text-error"
                                                title="Delete Certificate">
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-6 text-base-content/60">
                                    {{ __('admin.certificates_no_certificates') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif

