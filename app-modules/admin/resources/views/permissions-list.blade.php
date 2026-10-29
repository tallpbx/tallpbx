<div>
    <x-page-header :title="__('admin.permissions_title')" :tooltip="__('admin.page_info_tooltip')">
        <x-slot:subtitle>
            {{ __('admin.permissions_description') }}
            <a href="{{ route('panel.groups.index') }}" wire:navigate class="link link-primary">{{ __('admin.groups') }}</a> {{ __('admin.permissions_page') }}.
        </x-slot:subtitle>
    </x-page-header>

    {{-- Single table with Module column for consistent column alignment across sections --}}
    @php $allPermissions = collect($modules)->flatMap(fn ($m) => collect($permissions[$m] ?? [])->map(fn ($p) => ['module' => $m] + $p)); @endphp

    @if ($allPermissions->isNotEmpty())
        <div class="card bg-base-100 border border-base-300">
            <div class="overflow-x-auto">
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            <th class="w-32">{{ __('admin.module_name') }}</th>
                            <th class="w-80">{{ __('admin.permission_name') }}</th>
                            <th>{{ __('admin.permission_description') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($allPermissions as $perm)
                            <tr>
                                <td class="text-xs font-semibold text-base-content/50 uppercase tracking-wider">{{ $perm['module'] }}</td>
                                <td class="font-mono text-sm">{{ $perm['name'] }}</td>
                                <td class="text-sm text-base-content/70">
                                    {{ $perm['description'] ? __($perm['description']) : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body text-center py-8 text-base-content/40">
                {{ __('admin.no_permissions_found') }}.
            </div>
        </div>
    @endif
</div>
