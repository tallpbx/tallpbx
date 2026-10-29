<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <x-page-header :title="__('admin.cdr_detail_title')">
            <x-slot:actions>
                <a href="{{ route('panel.cdr.index') }}" wire:navigate class="btn btn-ghost">{{ __('client.back') }}</a>
            </x-slot:actions>
        </x-page-header>

        <dl class="grid grid-cols-2 gap-4">
            <div>
                <dt class="text-xs text-base-content/60">{{ __('admin.cdr_caller') }}</dt>
                <dd class="font-medium">{{ $cdr->caller_id_name }} ({{ $cdr->caller_id }})</dd>
            </div>
            <div>
                <dt class="text-xs text-base-content/60">{{ __('admin.cdr_destination') }}</dt>
                <dd class="font-mono">{{ $cdr->destination }}</dd>
            </div>
            <div>
                <dt class="text-xs text-base-content/60">{{ __('admin.cdr_direction') }}</dt>
                <dd><span class="badge badge-ghost">{{ $cdr->direction }}</span></dd>
            </div>
            <div>
                <dt class="text-xs text-base-content/60">{{ __('admin.cdr_duration') }}</dt>
                <dd>{{ gmdate('i:s', $cdr->duration) }} ({{ __('admin.cdr_billsec') }}: {{ gmdate('i:s', $cdr->billsec) }})</dd>
            </div>
            <div>
                <dt class="text-xs text-base-content/60">{{ __('admin.cdr_hangup_cause') }}</dt>
                <dd><span class="font-mono text-sm">{{ $cdr->hangup_cause }}</span></dd>
            </div>
            <div>
                <dt class="text-xs text-base-content/60">{{ __('admin.cdr_start_stamp') }}</dt>
                <dd>{{ $cdr->start_stamp?->format('M j, Y g:i:s A') }}</dd>
            </div>
            <div>
                <dt class="text-xs text-base-content/60">{{ __('admin.cdr_answer_stamp') }}</dt>
                <dd>{{ $cdr->answer_stamp?->format('M j, Y g:i:s A') ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-base-content/60">{{ __('admin.cdr_end_stamp') }}</dt>
                <dd>{{ $cdr->end_stamp?->format('M j, Y g:i:s A') }}</dd>
            </div>
            <div>
                <dt class="text-xs text-base-content/60">{{ __('admin.cdr_call_uuid') }}</dt>
                <dd><span class="font-mono text-xs">{{ $cdr->call_uuid }}</span></dd>
            </div>
        </dl>
    </div>
</div>
