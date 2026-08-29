<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Cuentas por Cobrar</title>
    <style>
        @page { margin: 1.4cm; }
        body { font-family: 'Helvetica','Arial',sans-serif; font-size: 10pt; color: #1a1a1a; }
        h1 { font-size: 16pt; margin: 0 0 2pt 0; color: #1d4ed8; }
        .meta { color: #555; font-size: 8.5pt; margin-bottom: 12pt; }
        table { width: 100%; border-collapse: collapse; font-size: 9pt; }
        thead th { background: #f1f5f9; text-align: left; padding: 5pt; border-bottom: 2px solid #cbd5e1; text-transform: uppercase; font-size: 8pt; color: #475569; }
        td { padding: 5pt; border-bottom: 1px solid #e2e8f0; }
        td.right, th.right { text-align: right; }
        .total { margin-top: 10pt; text-align: right; font-weight: bold; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
    <h1>Cuentas por Cobrar</h1>
    <div class="meta">Generado el {{ $generatedAt->format('d/m/Y H:i') }} · {{ $rows->count() }} cuentas</div>
    <table>
        <thead>
            <tr>
                <th>Documento</th>
                <th>Cliente</th>
                <th>Estado</th>
                <th>Vence</th>
                <th class="right">Original</th>
                <th class="right">Cobrado</th>
                <th class="right">Saldo</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    <td>{{ $row->document_number ?? 'CxC #'.$row->id }}</td>
                    <td>{{ $row->customer?->name ?? 'Consumidor Final' }}</td>
                    <td>{{ $row->status }}</td>
                    <td>{{ $row->due_date?->toDateString() ?? '-' }}</td>
                    <td class="right">$ {{ number_format((float) $row->original_base_amount, 2, ',', '.') }}</td>
                    <td class="right">$ {{ number_format((float) $row->collected_base_amount, 2, ',', '.') }}</td>
                    <td class="right">$ {{ number_format((float) $row->balance_base_amount, 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="total">Saldo total: $ {{ number_format((float) $rows->sum('balance_base_amount'), 2, ',', '.') }}</div>
</body>
</html>
