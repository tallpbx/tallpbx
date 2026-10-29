{{-- Live Terminal Console & Update Steps --}}
@if ($updating || ! empty($terminalOutput) || ! empty($updateSteps))
    <div class="card bg-base-100 border border-base-300 overflow-hidden shadow-sm">
        {{-- Terminal Window Header --}}
        <div class="bg-base-300/80 px-4 py-3 border-b border-base-300 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-full bg-[#ff5f56] inline-block"></span>
                    <span class="w-3 h-3 rounded-full bg-[#ffbd2e] inline-block"></span>
                    <span class="w-3 h-3 rounded-full bg-[#27c93f] inline-block"></span>
                </div>
                <span class="font-mono text-xs text-base-content/70 font-semibold ml-2">bash — update pipeline</span>
            </div>
            <div class="flex items-center gap-2">
                @if ($updating)
                    <span class="badge badge-sm badge-warning gap-1.5 animate-pulse">
                        <span class="w-1.5 h-1.5 rounded-full bg-warning-content animate-ping"></span>
                        {{ $currentStep ? $currentStep.'...' : 'Updating...' }}
                    </span>
                @elseif ($updateSuccess)
                    <span class="badge badge-sm badge-success gap-1">
                        <x-heroicon-s-check class="w-3.5 h-3.5" />
                        Completed
                    </span>
                @elseif ($updateError)
                    <span class="badge badge-sm badge-error gap-1">
                        <x-heroicon-s-x-mark class="w-3.5 h-3.5" />
                        Failed
                    </span>
                @endif
            </div>
        </div>

        {{-- Terminal Console Body --}}
        <div class="bg-[#0c1017] p-4 text-emerald-400 font-mono text-xs overflow-y-auto max-h-96 leading-relaxed select-text"
             x-data="{ autoScroll() { this.$el.scrollTop = this.$el.scrollHeight } }"
             x-init="autoScroll()"
             x-effect="autoScroll()">
            @if ($terminalOutput !== '')
                <pre class="font-mono whitespace-pre-wrap">{{ $terminalOutput }}</pre>
            @elseif ($updating)
                <div class="flex items-center gap-2 text-emerald-400/70">
                    <span class="loading loading-dots loading-xs"></span>
                    <span>Initializing update environment...</span>
                </div>
            @else
                <span class="text-base-content/40">No terminal output recorded.</span>
            @endif
        </div>

        {{-- Step Checklist Footer --}}
        @if (! empty($updateSteps))
            <div class="p-4 bg-base-100 border-t border-base-300">
                <h4 class="text-xs font-semibold uppercase tracking-wider text-base-content/60 mb-2.5">{{ __('admin.git_update_steps') }}</h4>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2">
                    @foreach ($updateSteps as $step)
                        <div class="flex items-center gap-2 px-2.5 py-1.5 rounded-lg border text-xs {{ $step['status'] === 'ok' ? 'border-success/30 bg-success/5 text-success' : ($step['status'] === 'failed' ? 'border-error/30 bg-error/5 text-error' : 'border-base-300 bg-base-200/50 text-base-content/70') }}">
                            <span class="shrink-0 font-bold">
                                {{ $step['status'] === 'ok' ? '✓' : ($step['status'] === 'failed' ? '✗' : '·') }}
                            </span>
                            <span class="font-medium truncate">{{ $step['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endif
