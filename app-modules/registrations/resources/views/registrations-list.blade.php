<div>
    <x-page-header :title="__('admin.registrations_title')" :tooltip="__('admin.page_info_tooltip')" />

    <x-websocket-health-banner />

    @if (!$fsConnected)
        <x-inline-alert type="warning">{{ __('admin.fs_not_connected') }}</x-inline-alert>
    @elseif (count($registrations) === 0)
        <div class="card bg-base-100 border border-base-300">
            <div class="text-center py-8 text-base-content/40">{{ __('admin.no_registrations_found') }}</div>
        </div>
    @else
        <div class="card bg-base-100 border border-base-300">
            <div class="overflow-x-auto">
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            <th>{{ __('admin.sip_username') }}</th>
                            <th>{{ __('admin.registrations_contact') }}</th>
                            <th>{{ __('admin.registrations_expires') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($registrations as $reg)
                            <tr>
                                <td class="font-medium">{{ $reg['user'] }}</td>
                                <td><span class="font-mono text-xs">{{ $reg['contact'] }}</span></td>
                                <td>{{ $reg['expires'] }}s</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
