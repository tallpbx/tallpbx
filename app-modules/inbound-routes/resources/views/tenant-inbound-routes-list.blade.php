<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2"><h2 class="text-2xl font-semibold">{{ __('admin.inbound_routes_title') }}</h2><x-tooltip :tip="__('admin.page_info_tooltip')" position="right"><x-heroicon-o-information-circle class="w-5 h-5 cursor-help opacity-40 hover:opacity-80" /></x-tooltip></div>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.route_name') }}</th>
                        <th>{{ __('admin.route_destination') }}</th>
                        <th>{{ __('admin.route_action') }}</th>
                        <th>{{ __('admin.route_priority') }}</th>
                        <th>{{ __('client.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($routes as $route)
                        <tr>
                            <td class="font-medium">{{ $route->name }}</td>
                            <td>{{ $route->destination_number }}</td>
                            <td>{{ $route->action }}</td>
                            <td>{{ $route->priority }}</td>
                            <td>
                                @if($route->enabled)
                                    <span class="badge badge-success">{{ __('client.active') }}</span>
                                @else
                                    <span class="badge badge-ghost">{{ __('client.inactive') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-8 text-base-content/40">
                                {{ __('admin.no_inbound_routes_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
