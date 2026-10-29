{{-- Custom Rules Section Header --}}
<tr class="bg-base-200/20 text-xs font-semibold text-base-content/70">
    <td colspan="9" class="py-2 px-3">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="uppercase tracking-wider text-xs">{{ __('admin.security_custom_rules_title') }}</span>
            </div>
            <span class="text-xs font-normal text-base-content/60">{{ __('admin.security_pipeline_step4_desc') }}</span>
        </div>
    </td>
</tr>

{{-- Custom Sequential Rules Rows --}}
@forelse ($this->rules as $rule)
    <tr class="hover {{ ! $rule->enabled ? 'opacity-50' : '' }}"
        wire:key="firewall-rule-{{ $rule->id }}"
        wire:loading.class="opacity-40 pointer-events-none"
        wire:target="toggleRule({{ $rule->id }}), moveRuleUp({{ $rule->id }}), moveRuleDown({{ $rule->id }}), openCustomRuleModal({{ $rule->id }}), deleteRule({{ $rule->id }})">
        {{-- Status Toggle --}}
        <td class="text-center px-1">
            <input wire:click="toggleRule({{ $rule->id }})" type="checkbox"
                   wire:loading.attr="disabled"
                   wire:target="toggleRule({{ $rule->id }})"
                   aria-label="{{ ($rule->description ?: $rule->source_ip).' '.($rule->enabled ? __('client.enabled') : __('client.disabled')) }}"
                   class="toggle toggle-primary toggle-sm"
                   @checked($rule->enabled) />
        </td>

        {{-- Rule Name / Description --}}
        <td class="px-3">
            <div class="font-medium text-base-content">{{ $rule->description }}</div>
            @if ($rule->service)
                <div class="text-xs text-base-content/60">{{ $rule->service->name }}</div>
            @endif
        </td>

        {{-- Protocol --}}
        <td class="text-center font-mono text-xs font-semibold text-base-content/80 px-1.5">
            @if ($rule->service)
                {{ $rule->service->protocol === 'both' ? 'TCP/UDP' : strtoupper($rule->service->protocol) }}
            @else
                {{ match (strtolower((string) $rule->custom_protocol)) {
                    'all' => 'ALL',
                    default => strtoupper((string) $rule->custom_protocol),
                } }}
            @endif
        </td>

        {{-- Port --}}
        <td class="px-2">
            @if ($rule->service)
                <span class="font-mono text-sm text-base-content/70 whitespace-nowrap">{{ $rule->service->port_range }}</span>
            @else
                <span class="font-mono text-sm">{{ $rule->custom_port ?? '—' }}</span>
            @endif
        </td>

        {{-- Source Network --}}
        <td class="px-2">
            @if ($rule->source_ip === 'any' || $rule->source_ip === '0.0.0.0/0')
                <span class="badge badge-ghost badge-xs text-center inline-block max-w-full">{{ __('admin.security_source_anywhere') }}</span>
            @else
                <span class="font-mono text-xs text-base-content/70">{{ $rule->source_ip }}</span>
            @endif
        </td>

        {{-- Action Badge --}}
        <td class="text-center px-1.5">
            @if ($rule->action === 'accept')
                <span class="badge badge-success badge-sm text-center inline-block max-w-full">{{ __('admin.security_action_allow') }}</span>
            @else
                <span class="badge badge-error badge-sm text-center inline-block max-w-full">{{ __('admin.security_action_block') }}</span>
            @endif
        </td>

        {{-- Reorder | edit | delete each get their own column so they line up
             with every other section. Delete carries the destructive red tone. --}}
        <td class="text-center px-1 w-10">
            <div class="flex flex-col items-center gap-0.5">
                <button wire:click="moveRuleUp({{ $rule->id }})" type="button" aria-label="{{ __('admin.security_move_up') }}" class="btn btn-ghost btn-xs btn-square text-base-content/70 hover:text-base-content">
                    <x-heroicon-s-chevron-up class="w-3.5 h-3.5" />
                </button>
                <button wire:click="moveRuleDown({{ $rule->id }})" type="button" aria-label="{{ __('admin.security_move_down') }}" class="btn btn-ghost btn-xs btn-square text-base-content/70 hover:text-base-content">
                    <x-heroicon-s-chevron-down class="w-3.5 h-3.5" />
                </button>
            </div>
        </td>
        <td class="text-center px-1 w-10">
            <x-icon-button icon="heroicon-o-pencil" :label="__('client.edit').' '.$rule->source_ip" wire:click="openCustomRuleModal({{ $rule->id }})" class="btn-square text-base-content/70 hover:text-base-content" />
        </td>
        <td class="text-center px-1 w-10">
            <x-icon-button icon="heroicon-o-trash" :label="__('client.delete').' '.$rule->source_ip" wire:click="deleteRule({{ $rule->id }})" class="btn-square text-error hover:text-error/80" />
        </td>
    </tr>
@empty
    <tr>
        <td colspan="9" class="text-center py-4 text-base-content/60">
            <div class="flex items-center justify-center gap-2">
                <x-heroicon-o-shield-check class="w-4 h-4 text-success" />
                <span class="text-sm text-base-content/70">{{ __('admin.security_no_rules_help') }}</span>
            </div>
        </td>
    </tr>
@endforelse

