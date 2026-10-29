{{-- Default Inbound Policy Section Header --}}
<tr class="bg-base-200/20 text-xs font-semibold text-base-content/70">
    <td colspan="9" class="py-2 px-3">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="uppercase tracking-wider text-xs">{{ __('admin.security_default_policy') }}</span>
            </div>
            <span class="text-xs font-normal text-base-content/60">{{ __('admin.security_pipeline_step5_desc') }}</span>
        </div>
    </td>
</tr>
{{-- Default Inbound Fallback Policy Row --}}
<tr class="hover"
    wire:loading.class="opacity-40 pointer-events-none"
    wire:target="saveDefaultPolicy">
    <td class="text-center px-1">
        <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full bg-base-content/40" title="Active"></span>
    </td>
    <td class="px-3">
        <div class="font-medium text-base-content">{{ __('admin.security_default_policy') }}</div>
        <div class="text-xs text-base-content/60">{{ __('admin.security_unmatched_traffic') }}</div>
    </td>
    <td class="text-center text-xs font-mono text-base-content/70 px-1.5">
        ALL
    </td>
    <td class="text-xs text-base-content/60 px-2">
        <span title="{{ __('admin.security_all_remaining_traffic') }}">{{ __('admin.security_all_ports') }}</span>
    </td>
    <td class="px-2">
        <span class="badge badge-ghost badge-xs text-center inline-block max-w-full">{{ __('admin.security_source_anywhere') }}</span>
    </td>
    <td class="text-center px-1.5">
        <span wire:loading.remove wire:target="saveDefaultPolicy">
            @if ($firewallDefaultPolicy === 'drop')
                <span class="badge badge-error badge-sm font-semibold text-center inline-block max-w-full">{{ __('admin.security_action_drop') }}</span>
            @else
                <span class="badge badge-success badge-sm font-semibold text-center inline-block max-w-full">{{ __('admin.security_action_allow') }}</span>
            @endif
        </span>
        <span wire:loading wire:target="saveDefaultPolicy" class="inline-flex items-center gap-1.5">
            <span class="loading loading-spinner loading-xs text-primary"></span>
            <span class="badge badge-ghost badge-sm font-semibold text-base-content/60 text-center inline-block max-w-full">{{ __('admin.saving') }}</span>
        </span>
    </td>
    {{-- Reorder and delete reserved empty; configure becomes an icon-only control
         so it lines up in the shared primary-action column. --}}
    <td class="text-center px-1 w-10"><span class="text-base-content/30 font-mono" aria-hidden="true">&ndash;</span></td>
    <td class="text-center px-1 w-10">
        <button wire:click="openDefaultPolicyForm"
                wire:loading.attr="disabled"
                wire:target="saveDefaultPolicy"
                type="button"
                aria-label="{{ __('admin.security_configure') }}"
                class="btn btn-ghost btn-xs btn-square text-base-content/70 hover:text-base-content">
            <x-heroicon-o-cog-6-tooth class="w-4 h-4" wire:loading.remove wire:target="saveDefaultPolicy" />
            <span wire:loading wire:target="saveDefaultPolicy" class="loading loading-spinner loading-xs text-primary"></span>
        </button>
    </td>
    <td class="text-center px-1 w-10"><span class="text-base-content/30 font-mono" aria-hidden="true">&ndash;</span></td>
</tr>

