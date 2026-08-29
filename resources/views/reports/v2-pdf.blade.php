<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $report['name'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .muted { color: #6b7280; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th { text-align: left; background: #f3f4f6; padding: 6px 8px; border: 1px solid #e5e7eb; }
        td { padding: 6px 8px; border: 1px solid #e5e7eb; }
        tr.total td { font-weight: bold; background: #eef2ff; }
    </style>
</head>
<body>
    <h1>{{ $report['name'] }}</h1>
    <div class="muted">
        Dimensión: {{ $report['dimension'] }} ·
        Ámbito: {{ $scope === 'organization' ? 'Todo el grupo' : 'Empresa' }}
        @if ($period)
            · {{ $period['from'] }} al {{ $period['to'] }}
        @endif
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($headings as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
            <tr class="total">
                @foreach ($totalsRow as $cell)
                    <td>{{ $cell }}</td>
                @endforeach
            </tr>
        </tbody>
    </table>
</body>
</html>
