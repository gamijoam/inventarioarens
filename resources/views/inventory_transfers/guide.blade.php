<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Guia de Traslado {{ $transfer->document_number ?? '#' . $transfer->id }}</title>
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
        .section {
            margin: 18pt 0;
        }
        .section h2 {
            font-size: 12pt;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #2563eb;
            border-bottom: 1px solid #e5e5e5;
            padding-bottom: 4pt;
            margin: 0 0 8pt 0;
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
        table.items td {
            padding: 6pt;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        .imei-list {
            margin: 2pt 0 0 0;
            padding: 0;
            list-style: none;
            font-family: 'Courier New', monospace;
            font-size: 8.5pt;
            color: #475569;
        }
        .imei-list li {
            padding: 1pt 0;
        }
        .badge {
            display: inline-block;
            padding: 2pt 6pt;
            font-size: 8.5pt;
            font-weight: bold;
            border-radius: 3pt;
            color: #fff;
        }
        .badge-draft { background: #94a3b8; }
        .badge-prepared { background: #3b82f6; }
        .badge-prepared-diff { background: #f59e0b; }
        .badge-dispatched { background: #8b5cf6; }
        .badge-completed { background: #10b981; }
        .badge-completed-diff { background: #f97316; }
        .badge-cancelled { background: #ef4444; }
        .signatures {
            margin-top: 32pt;
            page-break-inside: avoid;
        }
        .signature-block {
            display: inline-block;
            width: 45%;
            margin: 0 2%;
            text-align: center;
        }
        .signature-line {
            border-top: 1px solid #1a1a1a;
            margin-top: 40pt;
            padding-top: 4pt;
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
    <div class="header">
        <h1>Guia de Traslado</h1>
        <div class="doc">{{ $transfer->document_number ?? 'TRF-' . str_pad((string) $transfer->sequence, 6, '0', STR_PAD_LEFT) }}</div>
        <div class="meta">
            Guia numero {{ $transfer->guide_number ?? 'N/A' }} ·
            Generada el {{ $generatedAt->format('d/m/Y H:i') }} ·
            Estado: <strong>{{ $transfer->status }}</strong>
        </div>
    </div>

    <div class="section">
        <h2>Informacion general</h2>
        <div class="grid">
            <div>
                <div class="label">Almacen origen</div>
                <div class="value">
                    {{ $transfer->fromWarehouse?->code ?? 'N/A' }}
                    — {{ $transfer->fromWarehouse?->name ?? '' }}
                </div>
            </div>
            <div>
                <div class="label">Almacen destino</div>
                <div class="value">
                    {{ $transfer->toWarehouse?->code ?? 'N/A' }}
                    — {{ $transfer->toWarehouse?->name ?? '' }}
                </div>
            </div>
        </div>
        @if ($transfer->reason)
            <div style="margin-top: 6pt;">
                <span class="label">Motivo:</span>
                <span class="value" style="display: inline;">{{ $transfer->reason }}</span>
            </div>
        @endif
        @if ($transfer->reference)
            <div>
                <span class="label">Referencia:</span>
                <span class="value" style="display: inline;">{{ $transfer->reference }}</span>
            </div>
        @endif
    </div>

    @if ($transfer->driver)
        <div class="section">
            <h2>Transportista</h2>
            <div class="grid">
                <div>
                    <div class="label">Nombre</div>
                    <div class="value">{{ $transfer->driver->name }}</div>
                </div>
                @if ($transfer->driver->document_number)
                    <div>
                        <div class="label">Documento</div>
                        <div class="value">{{ $transfer->driver->document_number }}</div>
                    </div>
                @endif
                @if ($transfer->driver->phone)
                    <div>
                        <div class="label">Telefono</div>
                        <div class="value">{{ $transfer->driver->phone }}</div>
                    </div>
                @endif
                @if ($transfer->driver->vehicle_plate)
                    <div>
                        <div class="label">Placa del vehiculo</div>
                        <div class="value">{{ $transfer->driver->vehicle_plate }}</div>
                    </div>
                @endif
                @if ($transfer->driver->carrier_company)
                    <div>
                        <div class="label">Empresa transportista</div>
                        <div class="value">{{ $transfer->driver->carrier_company }}</div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="section">
        <h2>Items</h2>
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 8%;">#</th>
                    <th style="width: 30%;">Producto</th>
                    <th style="width: 12%;">SKU</th>
                    <th style="width: 8%;" class="text-right">Pedido</th>
                    <th style="width: 8%;" class="text-right">Preparado</th>
                    <th style="width: 8%;" class="text-right">Recibido</th>
                    <th style="width: 26%;">IMEIs / Seriales</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($transfer->items as $i => $item)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $item->product?->name ?? 'Producto #' . $item->product_id }}</td>
                        <td>
                            @if ($item->product?->sku)
                                <code style="font-size: 9pt;">{{ $item->product->sku }}</code>
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-right">{{ number_format((float) $item->quantity, 2) }}</td>
                        <td class="text-right">{{ number_format((float) ($item->prepared_quantity ?? 0), 2) }}</td>
                        <td class="text-right">{{ number_format((float) ($item->received_quantity ?? 0), 2) }}</td>
                        <td>
                            @if (! empty($item->serial_units) && is_array($item->serial_units))
                                <ul class="imei-list">
                                    @foreach (array_slice($item->serial_units, 0, 6) as $serial)
                                        <li>
                                            @if (is_array($serial))
                                                <strong>{{ strtoupper($serial['serial_type'] ?? 'SN') }}:</strong>
                                                {{ $serial['serial_number'] ?? '' }}
                                            @else
                                                {{ $serial }}
                                            @endif
                                        </li>
                                    @endforeach
                                    @if (count($item->serial_units) > 6)
                                        <li><em>+ {{ count($item->serial_units) - 6 }} mas...</em></li>
                                    @endif
                                </ul>
                            @else
                                <span style="color: #888;">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($transfer->notes)
        <div class="section">
            <h2>Notas</h2>
            <p style="margin: 0; white-space: pre-wrap;">{{ $transfer->notes }}</p>
        </div>
    @endif

    <div class="signatures">
        <div class="signature-block">
            <div class="signature-line">
                <strong>{{ $transfer->driver?->name ?? 'Transportista' }}</strong><br>
                <span style="font-size: 9pt; color: #666;">Firma del transportista</span><br>
                @if ($transfer->driver?->signed_by_driver_at)
                    <span style="font-size: 8pt; color: #10b981;">Firmado: {{ $transfer->driver->signed_by_driver_at->format('d/m/Y H:i') }}</span>
                @endif
            </div>
        </div>
        <div class="signature-block">
            <div class="signature-line">
                <strong>Receptor</strong><br>
                <span style="font-size: 9pt; color: #666;">Firma del receptor</span><br>
                @if ($transfer->driver?->signed_by_receiver_at)
                    <span style="font-size: 8pt; color: #10b981;">Firmado: {{ $transfer->driver->signed_by_receiver_at->format('d/m/Y H:i') }}</span>
                @endif
            </div>
        </div>
    </div>

    <div class="footer">
        Documento generado electronicamente por el sistema de gestion de
        inventario. Valido sin firma manuscrita solo si incluye firmas
        digitales en el sistema (signature_driver_url, signature_receiver_url).
    </div>
</body>
</html>
