<div>
    <x-page-header :title="__('admin.sip_status_title')" :tooltip="__('admin.page_info_tooltip')" />

    @if (!$fsConnected)
        <x-inline-alert type="warning">{{ __('admin.fs_not_connected') }}</x-inline-alert>
    @elseif (count($profiles) === 0)
        <div class="card bg-base-100 border border-base-300">
            <div class="text-center py-8 text-base-content/40">{{ __('admin.no_sip_profiles_found') }}</div>
        </div>
    @else
        <div class="card bg-base-100 border border-base-300">
            <div class="overflow-x-auto">
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            <th>{{ __('admin.profile_name') }}</th>
                            <th>{{ __('client.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($profiles as $profile)
                            <tr>
                                <td class="font-medium">{{ $profile['name'] }}</td>
                                <td><span class="badge @if($profile['status']==='RUNNING') badge-success @else badge-error @endif badge-sm">{{ $profile['status'] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
