<div>
    <x-page-header :title="__('admin.outbound_routes_title')" :tooltip="__('admin.page_info_tooltip')" />

    <div class="card bg-base-100 border border-base-300">
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>{{ __('admin.route_name') }}</th>
                        <th>{{ __('admin.route_dial_pattern') }}</th>
                        <th>{{ __('admin.route_gateway') }}</th>
                        <th>{{ __('admin.route_priority') }}</th>
                        <th>{{ __('client.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($routes as $route)
                        <tr>
                            <td class="font-medium">{{ $route->name }}</td>
                            <td><code class="badge badge-primary">{{ $route->dial_pattern }}</code></td>
                            <td>{{ $route->gatewayRelation?->name ?? $route->gateway ?? '—' }}</td>
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
                                {{ __('admin.no_outbound_routes_found') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if (method_exists($routes, 'links'))
        <div class="mt-4">
            {{ $routes->links() }}
        </div>
    @endif
</div>
