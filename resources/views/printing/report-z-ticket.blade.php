@php
    $width = (int) ($profile['paper_width_mm'] ?? 58);
    $fontSize = $width === 58 ? '10px' : '11px';
    $money = fn ($value) => '$'.number_format((float) $value, 2, '.', ',');
    $bs = fn ($value) => 'Bs '.number_format((float) $value, 2, ',', '.');
    $dt = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i') : '-';
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte Z #{{ $z['z_number'] ?? '' }}</title>
    <style>
        @page { margin: 4mm; size: {{ $width }}mm auto; }
        body { width: {{ $width - 6 }}mm; margin: 0 auto; color: #111; font-family: DejaVu Sans, monospace; font-size: {{ $fontSize }}; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: 700; }
        .muted { color: #555; }
        .line { border-top: 1px dashed #111; margin: 6px 0; }
        .row { display: flex; justify-content: space-between; gap: 8px; }
        .small { font-size: 9px; }
        .znum { font-size: 14px; font-weight: 700; }
    </style>
</head>
<body>
    <div class="center">
        <div class="bold">{{ $profile['logo_text'] ?: ($z['tenant']['name'] ?? 'Sistema de Inventario') }}</div>
        @if(!empty($z['tenant']['show_company']) && !empty($z['tenant']['company']['rif']))
            <div class="small">{{ $z['tenant']['company']['razon_social'] ?: ($z['tenant']['name'] ?? '') }}</div>
            <div class="small">RIF: {{ $z['tenant']['company']['rif'] }}</div>
            @if(!empty($z['tenant']['company']['domicilio_fiscal']))
                <div class="small">{{ $z['tenant']['company']['domicilio_fiscal'] }}</div>
            @endif
            @if(!empty($z['tenant']['company']['telefono']))
                <div class="small">Telf: {{ $z['tenant']['company']['telefono'] }}</div>
            @endif
        @endif
        @if(!empty($profile['header_text']))
            <div>{!! nl2br(e($profile['header_text'])) !!}</div>
        @endif
        <div class="znum">REPORTE Z</div>
        <div class="small muted">Z #{{ $z['z_number'] ?? '-' }}</div>
    </div>

    <div class="line"></div>
    <div>Caja: {{ $z['cash_register'] ?? '-' }}</div>
    <div>Sucursal: {{ $z['branch'] ?? '-' }}</div>
    <div>Cajero: {{ $z['cashier'] ?? '-' }}</div>
    <div>Apertura: {{ $dt($z['opened_at'] ?? null) }}</div>
    <div>Cierre: {{ $dt($z['closed_at'] ?? null) }}</div>

    <div class="line"></div>
    <div class="bold">Totales del turno</div>
    <div class="row"><span>Tickets</span><span>{{ $z['totals']['orders_count'] ?? 0 }}</span></div>
    <div class="row bold"><span>Total USD</span><span>{{ $money($z['totals']['paid_base_amount'] ?? 0) }}</span></div>
    <div class="row"><span>Total VES</span><span>{{ $bs($z['totals']['paid_local_amount'] ?? 0) }}</span></div>

    <div class="line"></div>
    <div class="bold">Pagos</div>
    @foreach(($z['payments'] ?? []) as $payment)
        <div class="row">
            <span>{{ $payment['name'] }} ({{ $payment['currency'] }})</span>
            <span>{{ $payment['currency'] === 'VES' ? $bs($payment['amount_local']) : $money($payment['amount_base']) }}</span>
        </div>
        @if(!empty($payment['exchange_rate']))
            <div class="small muted">tasa @ {{ number_format((float) $payment['exchange_rate'], 2, ',', '.') }}</div>
        @endif
    @endforeach
    @if(empty($z['payments']))
        <div class="small muted">Sin pagos registrados.</div>
    @endif

    <div class="line"></div>
    <div class="bold">Arqueo</div>
    <div class="row"><span>Esperado USD</span><span>{{ $money($z['totals']['expected_base_amount'] ?? 0) }}</span></div>
    <div class="row"><span>Contado USD</span><span>{{ $money($z['totals']['counted_base_amount'] ?? 0) }}</span></div>
    <div class="row"><span>Diferencia USD</span><span>{{ $money($z['totals']['difference_base_amount'] ?? 0) }}</span></div>
    <div class="row"><span>Diferencia efectivo USD</span><span>{{ $money($z['totals']['difference_cash_usd'] ?? 0) }}</span></div>
    <div class="row"><span>Diferencia efectivo VES</span><span>{{ $bs($z['totals']['difference_cash_ves'] ?? 0) }}</span></div>

    @if(!empty($z['counts']))
        <div class="line"></div>
        <div class="bold">Conteo por denominacion</div>
        @foreach($z['counts'] as $count)
            <div class="row small"><span>{{ $count['denomination'] }} {{ $count['currency'] }} x{{ $count['quantity'] }}</span><span>{{ $count['currency'] === 'VES' ? $bs($count['total_amount']) : $money($count['total_amount']) }}</span></div>
        @endforeach
    @endif

    <div class="line"></div>
    @if(!empty($profile['footer_text']))
        <div class="center">{!! nl2br(e($profile['footer_text'])) !!}</div>
    @endif
    @if($profile['show_non_fiscal_text'] ?? true)
        <div class="center small muted">{{ $profile['legal_text'] ?? 'Documento no fiscal' }}</div>
    @endif
</body>
</html>
