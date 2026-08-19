<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cotizacion {{ $quotation->document_number }}</title>
    <style>
        @page { margin: 1.5cm; }
        * { box-sizing: border-box; }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 11pt;
            color: #1a1a1a;
            margin: 0;
            padding: 0;
        }
        .header {
            border-bottom: 3px solid #2563eb;
            padding-bottom: 12pt;
            margin-bottom: 18pt;
        }
        .header h1 {
            margin: 0;
            font-size: 22pt;
            color: #2563eb;
        }
        .header .doc {
            font-family: 'Courier New', monospace;
            font-size: 13pt;
            color: #1a1a1a;
            font-weight: bold;
            margin-top: 4pt;
        }
        .header .meta {
            color: #666;
            font-size: 9pt;
            margin-top: 4pt;
        }
        .company {
            margin-bottom: 12pt;
        }
        .company .name {
            font-size: 14pt;
            font-weight: bold;
            color: #1a1a1a;
        }
        .company .data {
            color: #444;
            font-size: 9pt;
            margin-top: 2pt;
        }
        .grid {
            display: table;
            width: 100%;
            border-collapse: collapse;
        }
        .grid > div {
            display: table-cell;
            width: 50%;
            padding: 4pt 6pt;
            vertical-align: top;
        }
        .label {
            font-size: 8pt;
            text-transform: uppercase;
            color: #888;
            letter-spacing: 0.5px;
        }
        .value {
            font-size: 11pt;
            margin-top: 2pt;
        }
        table.items {
            width: 100%;
            border-collapse: collapse;
            font-size: 10pt;
        }
        table.items thead {
            background: #f1f5f9;
        }
        table.items th {
            text-align: left;
            padding: 6pt;
            font-size: 9pt;
            text-transform: uppercase;
            color: #475569;
            border-bottom: 2px solid #cbd5e1;
        }
        table.items th.right,
        table.items td.right {
            text-align: right;
        }
        table.items td {
            padding: 6pt;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        .totals {
            margin-top: 16pt;
            text-align: right;
        }
        .totals .row {
            padding: 2pt 0;
        }
        .totals .grand {
            font-size: 13pt;
            font-weight: bold;
            border-top: 2px solid #1a1a1a;
            padding-top: 6pt;
            margin-top: 4pt;
        }
        .notes {
            margin-top: 24pt;
            font-size: 9pt;
            color: #555;
        }
        .footer {
            margin-top: 32pt;
            padding-top: 8pt;
            border-top: 1px solid #e5e5e5;
            font-size: 8pt;
            color: #888;
            text-align: center;
        }
    </style>
</head>
<body>
    @if(!empty($show_company) && !empty($company['rif']))
        <div class="company">
            <div class="name">{{ $company['razon_social'] ?? '' }}</div>
            <div class="data">
                RIF: {{ $company['rif'] }}
                @if(!empty($company['domicilio_fiscal'])) · {{ $company['domicilio_fiscal'] }} @endif
                @if(!empty($company['ciudad']) || !empty($company['estado']))
                    · {{ trim(($company['ciudad'] ?? '').' '.($company['estado'] ?? '')) }}
                @endif
                @if(!empty($company['telefono'])) · Telf: {{ $company['telefono'] }} @endif
                @if(!empty($company['correo'])) · {{ $company['correo'] }} @endif
            </div>
        </div>
    @endif

    <div class="header">
        <h1>Cotizacion</h1>
        <div class="doc">{{ $quotation->document_number }}</div>
        <div class="meta">
            Emitida el {{ $quotation->issued_at?->format('d/m/Y H:i') ?: $quotation->created_at?->format('d/m/Y H:i') }} ·
            @if($quotation->valid_until) Valida hasta {{ $quotation->valid_until->format('d/m/Y') }} @endif
        </div>
    </div>

    <div class="grid">
        <div>
            <div class="label">Cliente</div>
            <div class="value">{{ $quotation->customer_name ?: 'Consumidor Final' }}</div>
        </div>
        <div>
            <div class="label">Almacen</div>
            <div class="value">{{ $quotation->warehouse?->name ?? '—' }}</div>
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Producto</th>
                <th>Variante</th>
                <th class="right">Cant.</th>
                <th class="right">Precio unit.</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quotation->items as $item)
                <tr>
                    <td>
                        <div>{{ $item->product_name }}</div>
                        @if(!empty($item->sku))
                            <div style="font-size: 8pt; color: #64748b;">{{ $item->sku }}</div>
                        @endif
                    </td>
                    <td>{{ $item->productVariant?->color ?? '—' }}</td>
                    <td class="right">{{ number_format((float) $item->quantity, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format((float) $item->unit_price_base, 2, ',', '.') }}</td>
                    <td class="right">$ {{ number_format((float) $item->total_base, 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div class="row">Subtotal: $ {{ number_format((float) $quotation->subtotal_base_amount, 2, ',', '.') }}</div>
        @if((float) $quotation->discount_base_amount > 0)
            <div class="row">Descuento: $ {{ number_format((float) $quotation->discount_base_amount, 2, ',', '.') }}</div>
        @endif
        <div class="row grand">Total: $ {{ number_format((float) $quotation->total_base_amount, 2, ',', '.') }}</div>
        @if((float) $quotation->total_local_amount > 0)
            <div class="row">Total Bs: {{ number_format((float) $quotation->total_local_amount, 2, ',', '.') }}</div>
        @endif
    </div>

    @if(!empty($quotation->notes))
        <div class="notes"><strong>Notas:</strong><br>{{ $quotation->notes }}</div>
    @endif

    <div class="footer">
        Documento no fiscal · Generado el {{ $generatedAt->format('d/m/Y H:i') }}
    </div>
</body>
</html>
