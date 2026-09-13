<div>
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">
                {{ __('Log Viewer') }}
            </h2>
        </div>

        {{-- Controls --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            {{-- File Selector --}}
            <div>
                <label for="logFile" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ __('Log File') }}
                </label>
                <select
                    id="logFile"
                    wire:model.live="selectedFile"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 sm:text-sm"
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
                <label for="lines" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ __('Lines') }}
                </label>
                <select
                    id="lines"
                    wire:model.live="lines"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 sm:text-sm"
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
                <label for="filterLevel" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ __('Level') }}
                </label>
                <select
                    id="filterLevel"
                    wire:model.live="filterLevel"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 sm:text-sm"
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
                <label for="search" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ __('Search') }}
                </label>
                <input
                    id="search"
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search log content...') }}"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 sm:text-sm"
                />
            </div>

            {{-- Auto-Refresh Toggle --}}
            <div class="flex items-end pb-2">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input
                        type="checkbox"
                        wire:model.live="autoRefresh"
                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800"
                    />
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Auto-refresh') }}
                    </span>
                </label>
            </div>
        </div>

        {{-- Log Content --}}
        <div
            wire:poll.5s="pollRefresh"
            class="relative rounded-lg border border-gray-200 dark:border-gray-700"
        >
            {{-- Loading indicator --}}
            <div wire:loading class="absolute inset-0 z-10 flex items-center justify-center rounded-lg bg-white/60 dark:bg-gray-900/60">
                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                    <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                    {{ __('Loading...') }}
                </div>
            </div>

            @if ($selectedFile === '')
                <div class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Select a log file to view its contents.') }}
                </div>
            @elseif ($logContent === '')
                <div class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    {{ __('No log entries match the current filters.') }}
                </div>
            @else
                <pre class="overflow-x-auto rounded-lg bg-gray-50 p-4 text-xs leading-5 text-gray-800 dark:bg-gray-900 dark:text-gray-200"><code>{{ $logContent }}</code></pre>
            @endif
        </div>
    </div>
</div>
