@php
    $profile = $ticket['profile'] ?? [];
    $width = (int) ($profile['paper_width_mm'] ?? 80);
    $fontSize = $width === 58 ? '10px' : '11px';
    $money = fn ($value) => '$'.number_format((float) $value, 2, '.', ',');
    $bs = fn ($value) => 'Bs '.number_format((float) $value, 2, ',', '.');
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ticket POS #{{ $ticket['pos_order']['id'] ?? '' }}</title>
    <style>
        @page { margin: 4mm; size: {{ $width }}mm auto; }
        body { width: {{ $width - 6 }}mm; margin: 0 auto; color: #111; font-family: DejaVu Sans, monospace; font-size: {{ $fontSize }}; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: 700; }
        .muted { color: #555; }
        .line { border-top: 1px dashed #111; margin: 6px 0; }
        .row { display: flex; justify-content: space-between; gap: 8px; }
        .item { margin-bottom: 5px; }
        .small { font-size: 9px; }
        .copy { border: 1px solid #111; padding: 2px 4px; display: inline-block; margin-bottom: 4px; }
    </style>
</head>
<body>
    <div class="center">
        @if(!empty($ticket['copy']))
            <div class="copy">COPIA</div>
        @endif
        <div class="bold">{{ $profile['logo_text'] ?: ($ticket['tenant']['name'] ?? 'Sistema de Inventario') }}</div>
        @if(!empty($profile['header_text']))
            <div>{!! nl2br(e($profile['header_text'])) !!}</div>
        @endif
        @if($profile['show_tenant_slug'] ?? true)
            <div class="small muted">{{ $ticket['tenant']['slug'] ?? '' }}</div>
        @endif
    </div>

    <div class="line"></div>
    <div>Ticket POS #{{ $ticket['pos_order']['id'] ?? '' }} @if(($profile['show_sale_number'] ?? true) && !empty($ticket['pos_order']['sale_id'])) - Venta #{{ $ticket['pos_order']['sale_id'] }} @endif</div>
    @if($profile['show_paid_at'] ?? true)<div>Fecha: {{ $ticket['pos_order']['paid_at'] ?? '' }}</div>@endif
    @if($profile['show_cashier'] ?? true)<div>Cajero: {{ $ticket['pos_order']['cashier_name'] ?? '-' }}</div>@endif
    @if($profile['show_cash_register'] ?? true)<div>Caja: {{ $ticket['pos_order']['cash_register_name'] ?? '-' }}</div>@endif
    @if($profile['show_branch'] ?? true)<div>Sucursal: {{ $ticket['pos_order']['branch_name'] ?? '-' }}</div>@endif
    @if($profile['show_customer'] ?? true)<div>Cliente: {{ $ticket['pos_order']['customer_name'] ?? 'Consumidor Final' }}</div>@endif

    <div class="line"></div>
    @foreach(($ticket['items'] ?? []) as $item)
        <div class="item">
            <div class="bold">{{ $item['product_name'] }}</div>
            @if(($profile['show_item_sku'] ?? true) && !empty($item['sku']))<div class="small muted">{{ $item['sku'] }}</div>@endif
            <div class="row"><span>{{ number_format((float) $item['quantity'], 2, ',', '.') }} x {{ $money($item['unit_price']) }}</span><span>{{ $money($item['total']) }}</span></div>
            @if(($profile['show_item_discount'] ?? true) && ($item['discount'] ?? 0) > 0)<div class="small">Desc: {{ $money($item['discount']) }}</div>@endif
            @if($profile['show_item_serials'] ?? true)
                @foreach(($item['serials'] ?? []) as $serial)
                    <div class="small">IMEI/Serial: {{ $serial['serial_number'] }}</div>
                @endforeach
            @endif
            @if(($profile['show_warranty_summary'] ?? true) && !empty($item['warranty']['name']))
                <div class="small">Garantia: {{ $item['warranty']['name'] }} - {{ $item['warranty']['duration_days'] }} dias @if(!empty($item['warranty']['expires_at'])) - vence {{ $item['warranty']['expires_at'] }} @endif</div>
            @endif
        </div>
    @endforeach

    <div class="line"></div>
    <div class="row bold"><span>Total USD</span><span>{{ $money($ticket['totals']['total_base_amount'] ?? 0) }}</span></div>
    @if($profile['show_total_local'] ?? true)<div class="row"><span>Total VES</span><span>{{ $bs($ticket['totals']['total_local_amount'] ?? 0) }}</span></div>@endif
    <div class="row"><span>Pagado USD</span><span>{{ $money($ticket['totals']['paid_base_amount'] ?? 0) }}</span></div>
    @if(($profile['show_receivable_balance'] ?? true) && ($ticket['totals']['balance_base_amount'] ?? 0) > 0)
        <div class="row bold"><span>Saldo CxC</span><span>{{ $money($ticket['totals']['balance_base_amount']) }}</span></div>
    @endif

    <div class="line"></div>
    <div class="bold">Pagos</div>
    @foreach(($ticket['payments'] ?? []) as $payment)
        <div class="row"><span>{{ $payment['method'] }} {{ $payment['currency'] }}</span><span>{{ $payment['currency'] === 'VES' ? $bs($payment['amount']) : $money($payment['amount']) }}</span></div>
        @if(($profile['show_payment_rate'] ?? true) && !empty($payment['exchange_rate']))
            <div class="small muted">{{ $payment['exchange_rate_type_code'] }} @ {{ number_format((float) $payment['exchange_rate'], 2, ',', '.') }}</div>
        @endif
        @if(($profile['show_payment_reference'] ?? true) && !empty($payment['reference']))<div class="small">Ref: {{ $payment['reference'] }}</div>@endif
    @endforeach

    <div class="line"></div>
    @if(!empty($profile['warranty_policy_text']))
        <div class="small">{!! nl2br(e($profile['warranty_policy_text'])) !!}</div>
        <div class="line"></div>
    @endif
    @if(!empty($profile['footer_text']))
        <div class="center">{!! nl2br(e($profile['footer_text'])) !!}</div>
    @else
        <div class="center">Gracias por su compra.</div>
    @endif
    @if($profile['show_non_fiscal_text'] ?? true)
        <div class="center small muted">{{ $profile['legal_text'] ?? 'Documento no fiscal' }}</div>
    @endif
</body>
</html>
