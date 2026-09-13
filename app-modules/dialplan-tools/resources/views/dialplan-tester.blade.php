<div class="card bg-base-100 shadow-xl">
    <div class="card-body">
        <h1 class="text-2xl font-bold mb-6">{{ __('admin.dialplan_tools_title') }}</h1>
        <p class="text-base-content/60 mb-6">{{ __('admin.dialplan_tools_description') }}</p>
        <form wire:submit="testRegex" class="space-y-6 max-w-lg">
            <div class="form-control w-full">
                <label class="label" for="testPattern"><span class="label-text">{{ __('admin.dialplan_tools_pattern') }}</span></label>
                <input wire:model="testPattern" id="testPattern" type="text" class="input input-bordered w-full font-mono" placeholder="^(\d{3})(\d{4})$" />
                @error('testPattern')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="form-control w-full">
                <label class="label" for="testNumber"><span class="label-text">{{ __('admin.dialplan_tools_number') }}</span></label>
                <input wire:model="testNumber" id="testNumber" type="text" class="input input-bordered w-full font-mono" placeholder="1234567" />
                @error('testNumber')<span class="text-error text-xs mt-1">{{ $message }}</span>@enderror
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">{{ __('admin.dialplan_tools_test') }}</button>
            </div>
        </form>

        @if($tested)
            <div class="mt-6 p-4 rounded-lg @if($hasMatch) bg-success/10 border border-success/30 @else bg-warning/10 border border-warning/30 @endif">
                @if($hasMatch)
                    <p class="font-semibold text-success">{{ __('admin.dialplan_tools_match') }}</p>
                    @if($matchGroups)
                        <div class="mt-2 space-y-1">
                            <p class="text-sm font-medium">{{ __('admin.dialplan_tools_captures') }}</p>
                            @foreach($matchGroups as $i => $group)
                                <p class="font-mono text-sm">${{ $i + 1 }} = {{ $group }}</p>
                            @endforeach
                        </div>
                    @endif
                @else
                    <p class="font-semibold text-warning">{{ __('admin.dialplan_tools_no_match') }}</p>
                @endif
            </div>
        @endif
    </div>
</div>
