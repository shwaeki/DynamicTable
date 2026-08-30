{{-- The expanded panel for one order. Rendered on the server, on demand. --}}
<div class="order-detail">
    <div class="order-detail-head">
        <div>
            <span class="order-detail-label">{{ __('demo.detail.customer') }}</span>
            <strong>{{ $order->customer?->name ?? '—' }}</strong>
            <span class="order-detail-muted">{{ $order->customer?->email }}</span>
        </div>
        <div>
            <span class="order-detail-label">{{ __('demo.detail.placed') }}</span>
            <strong>{{ optional($order->placed_at)->isoFormat('LL') ?? '—' }}</strong>
        </div>
        <div>
            <span class="order-detail-label">{{ __('demo.detail.shipped') }}</span>
            <strong>{{ optional($order->shipped_at)->isoFormat('LL') ?? '—' }}</strong>
        </div>
    </div>

    <table class="order-detail-items">
        <thead>
            <tr>
                <th>{{ __('demo.detail.product') }}</th>
                <th class="order-detail-end">{{ __('demo.detail.quantity') }}</th>
                <th class="order-detail-end">{{ __('demo.detail.unit_price') }}</th>
                <th class="order-detail-end">{{ __('demo.detail.line_total') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($order->items as $item)
                <tr>
                    <td>{{ $item->product?->name ?? '—' }}</td>
                    <td class="order-detail-end">{{ $item->quantity }}</td>
                    <td class="order-detail-end">${{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="order-detail-end">${{ number_format($item->quantity * (float) $item->unit_price, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="order-detail-muted">{{ __('demo.detail.no_items') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
