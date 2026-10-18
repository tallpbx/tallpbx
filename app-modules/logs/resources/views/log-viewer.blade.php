<div>
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <h2 class="text-2xl font-bold tracking-tight text-base-content">
                {{ __('Log Viewer') }}
            </h2>
        </div>

        {{-- Controls --}}
        <div class="card bg-base-100 border border-base-300 p-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                {{-- File Selector --}}
                <div>
                    <label for="logFile" class="block text-sm font-medium mb-1">
                        {{ __('Log File') }}
                    </label>
                    <select
                        id="logFile"
                        wire:model.live="selectedFile"
                        class="select select-bordered select-sm w-full"
                    >
                        <option value="">{{ __('Select a log file...') }}</option>
                        @foreach ($logFiles as $file)
                            <option value="{{ $file['name'] }}">
                                {{ $file['name'] }} ({{ number_format($file['size']) }} bytes)
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Lines --}}
                <div>
                    <label for="lines" class="block text-sm font-medium mb-1">
                        {{ __('Lines') }}
                    </label>
                    <select
                        id="lines"
                        wire:model.live="lines"
                        class="select select-bordered select-sm w-full"
                    >
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="200">200</option>
                        <option value="500">500</option>
                        <option value="1000">1000</option>
                    </select>
                </div>

                {{-- Level Filter --}}
                <div>
                    <label for="filterLevel" class="block text-sm font-medium mb-1">
                        {{ __('Level') }}
                    </label>
                    <select
                        id="filterLevel"
                        wire:model.live="filterLevel"
                        class="select select-bordered select-sm w-full"
                    >
                        <option value="">{{ __('All Levels') }}</option>
                        <option value="DEBUG">DEBUG</option>
                        <option value="INFO">INFO</option>
                        <option value="WARNING">WARNING</option>
                        <option value="ERROR">ERROR</option>
                        <option value="CRITICAL">CRITICAL</option>
                    </select>
                </div>

                {{-- Search --}}
                <div>
                    <label for="search" class="block text-sm font-medium mb-1">
                        {{ __('Search') }}
                    </label>
                    <input
                        id="search"
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search log content...') }}"
                        class="input input-bordered input-sm w-full"
                    />
                </div>

                {{-- Auto-Refresh Toggle --}}
                <div class="flex items-end pb-1">
                    <label class="inline-flex items-center gap-2 cursor-pointer"
                           wire:loading.class="opacity-70 pointer-events-none"
                           wire:target="autoRefresh">
                        <input
                            type="checkbox"
                            wire:model.live="autoRefresh"
                            wire:loading.attr="disabled"
                            wire:target="autoRefresh"
                            class="toggle toggle-primary toggle-sm"
                        />
                        <span class="text-sm font-medium">
                            {{ __('Auto-refresh') }}
                        </span>
                    </label>
                </div>
            </div>
        </div>

        {{-- Log Content --}}
        <div
            wire:poll.5s="pollRefresh"
            class="relative card bg-base-100 border border-base-300"
        >
            {{-- Loading indicator --}}
            <div wire:loading class="absolute inset-0 z-10 flex items-center justify-center rounded-lg bg-base-100/70 backdrop-blur-xs">
                <div class="flex items-center gap-2 text-sm text-base-content/70">
                    <span class="loading loading-spinner loading-sm text-primary"></span>
                    {{ __('Loading...') }}
                </div>
            </div>

            @if ($selectedFile === '')
                <div class="p-8 text-center text-sm text-base-content/50">
                    {{ __('Select a log file to view its contents.') }}
                </div>
            @elseif ($logContent === '')
                <div class="p-8 text-center text-sm text-base-content/50">
                    {{ __('No log entries match the current filters.') }}
                </div>
            @else
                <pre class="overflow-x-auto rounded-lg bg-base-200 p-4 text-xs font-mono leading-5 text-base-content"><code>{{ $logContent }}</code></pre>
            @endif
        </div>
    </div>
</div>
