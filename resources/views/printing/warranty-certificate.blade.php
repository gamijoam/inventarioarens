<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificado de Garantía {{ $cert['warranty']['number'] }}</title>
    <style>
        @page {
            size: letter portrait;
            margin: 10mm 12mm;
        }

        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #0f172a;
            background-color: #ffffff;
            margin: 0;
            padding: 0;
            font-size: 11px;
            line-height: 1.4;
        }

        .cert-container {
            max-width: 780px;
            margin: 0 auto;
            padding: 16px;
        }

        /* HEADER */
        .cert-header {
            text-align: center;
            border-bottom: 2px solid #0284c7;
            padding-bottom: 12px;
            margin-bottom: 14px;
            position: relative;
        }

        .cert-badge-title {
            display: inline-block;
            background-color: #0284c7;
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            padding: 3px 14px;
            border-radius: 9999px;
            margin-bottom: 8px;
        }

        .cert-business-name {
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin: 2px 0 4px 0;
        }

        .cert-business-sub {
            font-size: 10px;
            color: #64748b;
            font-weight: 500;
        }

        .cert-top-info {
            display: table;
            width: 100%;
            margin-bottom: 12px;
        }

        .cert-top-left {
            display: table-cell;
            vertical-align: middle;
            text-align: left;
        }

        .cert-top-right {
            display: table-cell;
            vertical-align: middle;
            text-align: right;
        }

        .cert-number-box {
            display: inline-block;
            background-color: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 6px 12px;
            text-align: right;
        }

        .cert-number-label {
            font-size: 9px;
            font-weight: 700;
            color: #0369a1;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .cert-number-val {
            font-size: 14px;
            font-weight: 800;
            color: #0c4a6e;
            font-family: 'Courier New', Courier, monospace;
        }

        .cert-issued-date {
            font-size: 9px;
            color: #64748b;
            margin-top: 2px;
        }

        /* 2 COLUMNS: SALE & CUSTOMER */
        .info-grid {
            display: table;
            width: 100%;
            margin-bottom: 14px;
            border-spacing: 10px 0;
            margin-left: -10px;
            margin-right: -10px;
        }

        .info-col {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
        }

        .info-col-title {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #334155;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 4px;
            margin-bottom: 6px;
        }

        .info-row {
            display: table;
            width: 100%;
            margin-bottom: 3px;
            font-size: 10.5px;
        }

        .info-lbl {
            display: table-cell;
            width: 90px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            font-size: 9.5px;
        }

        .info-val {
            display: table-cell;
            color: #0f172a;
            font-weight: 600;
        }

        /* SECTION TITLE */
        .section-title {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #0c4a6e;
            background-color: #e0f2fe;
            padding: 5px 10px;
            border-radius: 6px;
            margin-bottom: 8px;
            border-left: 4px solid #0284c7;
        }

        /* EQUIPMENT CARDS */
        .equipments-container {
            margin-bottom: 14px;
        }

        .equipment-card {
            background-color: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 8px 12px;
            margin-bottom: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            page-break-inside: avoid;
        }

        .eq-header {
            display: table;
            width: 100%;
            border-bottom: 1px dashed #e2e8f0;
            padding-bottom: 5px;
            margin-bottom: 6px;
        }

        .eq-name {
            display: table-cell;
            vertical-align: middle;
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
        }

        .eq-duration-badge {
            display: table-cell;
            vertical-align: middle;
            text-align: right;
        }

        .eq-badge {
            display: inline-block;
            background-color: #dbeafe;
            color: #1e40af;
            font-size: 9.5px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 4px;
            letter-spacing: 0.5px;
        }

        .eq-grid {
            display: table;
            width: 100%;
            font-size: 10px;
        }

        .eq-cell {
            display: table-cell;
            vertical-align: top;
            padding-right: 12px;
        }

        .eq-lbl {
            font-size: 8.5px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 1px;
        }

        .eq-val {
            font-weight: 600;
            color: #1e293b;
        }

        .imei-highlight {
            font-family: 'Courier New', Courier, monospace;
            background-color: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10.5px;
            font-weight: 700;
            color: #0f172a;
            display: inline-block;
        }

        /* 4 TERMS BOXES (2x2) */
        .terms-grid {
            display: table;
            width: 100%;
            border-spacing: 8px;
            margin-left: -8px;
            margin-right: -8px;
            margin-bottom: 14px;
            page-break-inside: avoid;
        }

        .terms-row {
            display: table-row;
        }

        .terms-box {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            border-radius: 6px;
            padding: 8px 10px;
            font-size: 9.5px;
        }

        .box-covers {
            background-color: #f0fdf4;
            border: 1px solid #bbf7d0;
        }

        .box-covers-title {
            color: #166534;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
            font-size: 9.5px;
        }

        .box-not-covers {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
        }

        .box-not-covers-title {
            color: #991b1b;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
            font-size: 9.5px;
        }

        .box-claim {
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
        }

        .box-claim-title {
            color: #334155;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
            font-size: 9.5px;
        }

        .box-important {
            background-color: #fffbeb;
            border: 1px solid #fde68a;
        }

        .box-important-title {
            color: #92400e;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
            font-size: 9.5px;
        }

        .terms-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .terms-list li {
            position: relative;
            padding-left: 14px;
            margin-bottom: 3px;
            color: #1e293b;
            line-height: 1.35;
        }

        .bullet-covers {
            position: absolute;
            left: 0;
            color: #16a34a;
            font-weight: 800;
        }

        .bullet-not-covers {
            position: absolute;
            left: 0;
            color: #dc2626;
            font-weight: 800;
        }

        .bullet-claim {
            position: absolute;
            left: 0;
            color: #0284c7;
            font-weight: 800;
        }

        .bullet-important {
            position: absolute;
            left: 0;
            color: #d97706;
            font-weight: 800;
        }

        /* SIGNATURE */
        .signature-block {
            text-align: center;
            margin-top: 18px;
            margin-bottom: 12px;
            page-break-inside: avoid;
        }

        .signature-line {
            display: inline-block;
            width: 260px;
            border-top: 1px solid #475569;
            padding-top: 5px;
            margin-bottom: 2px;
        }

        .signature-title {
            font-size: 10px;
            font-weight: 800;
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .signature-name {
            font-size: 10px;
            color: #475569;
            font-weight: 600;
        }

        /* FOOTER */
        .cert-footer {
            border-top: 1px solid #e2e8f0;
            padding-top: 8px;
            display: table;
            width: 100%;
            font-size: 9px;
            color: #94a3b8;
        }

        .footer-left {
            display: table-cell;
            text-align: left;
        }

        .footer-center {
            display: table-cell;
            text-align: center;
        }

        .footer-right {
            display: table-cell;
            text-align: right;
        }

        /* PRINT DIALOG BUTTON (HIDDEN IN PRINT) */
        .no-print {
            text-align: center;
            margin-bottom: 12px;
            padding: 8px;
            background: #f1f5f9;
            border-radius: 8px;
        }

        .btn-print {
            background-color: #0284c7;
            color: #ffffff;
            border: none;
            padding: 8px 16px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
        }

        .btn-print:hover {
            background-color: #0369a1;
        }

        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: none !important;
            }
            .cert-container {
                max-width: 100% !important;
                padding: 0 !important;
            }
        }
    </style>
</head>
<body>
    <div class="cert-container">

        <!-- HEADER -->
        <div class="cert-header">
            <div class="cert-badge-title">{{ $cert['warranty']['title'] }}</div>
            <div class="cert-business-name">{{ $cert['tenant']['name'] }}</div>
            @if($cert['tenant']['subtitle'])
                <div class="cert-business-sub">{{ $cert['tenant']['subtitle'] }}</div>
            @endif
        </div>

        <!-- TOP BAR: NUMBER & DATE -->
        <div class="cert-top-info">
            <div class="cert-top-left">
                <span style="font-size: 11px; font-weight: 700; color: #475569;">DOCUMENTO OFICIAL DE GARANTÍA</span>
            </div>
            <div class="cert-top-right">
                <div class="cert-number-box">
                    <div class="cert-number-label">N° de Garantía</div>
                    <div class="cert-number-val">{{ $cert['warranty']['number'] }}</div>
                    <div class="cert-issued-date">Emitido: {{ $cert['warranty']['issued_at'] }}</div>
                </div>
            </div>
        </div>

        <!-- 2 COLUMNS: SALE & CUSTOMER -->
        <div class="info-grid">
            <!-- VENTA -->
            <div class="info-col">
                <div class="info-col-title">Datos de la Venta</div>
                <div class="info-row">
                    <span class="info-lbl">Factura / Ticket:</span>
                    <span class="info-val">{{ $cert['sale']['invoice_number'] }}</span>
                </div>
                <div class="info-row">
                    <span class="info-lbl">Fecha de Pago:</span>
                    <span class="info-val">{{ $cert['sale']['date'] }}</span>
                </div>
                <div class="info-row">
                    <span class="info-lbl">Monto Total:</span>
                    <span class="info-val" style="color: #0369a1; font-weight: 700;">{{ $cert['sale']['total'] }}</span>
                </div>
            </div>

            <!-- CLIENTE -->
            <div class="info-col">
                <div class="info-col-title">Datos del Cliente</div>
                <div class="info-row">
                    <span class="info-lbl">Nombre:</span>
                    <span class="info-val">{{ $cert['customer']['name'] }}</span>
                </div>
                <div class="info-row">
                    <span class="info-lbl">C.I. / RIF:</span>
                    <span class="info-val">{{ $cert['customer']['document'] }}</span>
                </div>
                <div class="info-row">
                    <span class="info-lbl">Teléfono:</span>
                    <span class="info-val">{{ $cert['customer']['phone'] }}</span>
                </div>
                @if($cert['customer']['email'] && $cert['customer']['email'] !== 'N/A')
                <div class="info-row">
                    <span class="info-lbl">Correo:</span>
                    <span class="info-val">{{ $cert['customer']['email'] }}</span>
                </div>
                @endif
            </div>
        </div>

        <!-- EQUIPOS AMPARADOS -->
        <div class="section-title">Equipos Amparados por esta Garantía</div>
        <div class="equipments-container">
            @forelse($cert['equipments'] as $eq)
                <div class="equipment-card">
                    <div class="eq-header">
                        <div class="eq-name">{{ $eq['product_name'] }}</div>
                        <div class="eq-duration-badge">
                            <span class="eq-badge">{{ $eq['duration_text'] }}</span>
                        </div>
                    </div>
                    <div class="eq-grid">
                        <div class="eq-cell" style="width: 15%;">
                            <div class="eq-lbl">Cantidad</div>
                            <div class="eq-val">{{ $eq['quantity'] }}</div>
                        </div>
                        <div class="eq-cell" style="width: 45%;">
                            <div class="eq-lbl">IMEI / Serial</div>
                            <div class="eq-val">
                                @if($eq['imei_serial'] !== 'N/A')
                                    <span class="imei-highlight">{{ $eq['imei_serial'] }}</span>
                                @else
                                    <span style="color: #64748b;">N/A</span>
                                @endif
                            </div>
                        </div>
                        <div class="eq-cell" style="width: 40%;">
                            <div class="eq-lbl">Vence</div>
                            <div class="eq-val" style="color: #dc2626; font-weight: 700;">{{ $eq['expires_at'] }}</div>
                        </div>
                    </div>
                    <div style="margin-top: 6px; font-size: 9.5px; color: #475569; background-color: #f8fafc; padding: 4px 6px; border-radius: 4px;">
                        <strong>Política:</strong> {{ $eq['policy_name'] }} &nbsp;|&nbsp;
                        <strong>Cobertura:</strong> {{ $eq['conditions'] }}
                    </div>
                </div>
            @empty
                <div class="equipment-card" style="text-align: center; color: #64748b; padding: 12px;">
                    No se registraron equipos con garantía en esta venta.
                </div>
            @endforelse
        </div>

        <!-- TERMS: 4 BOXES -->
        <div class="terms-grid">
            <div class="terms-row">
                <!-- QUE SI CUBRE -->
                <div class="terms-box box-covers">
                    <div class="box-covers-title"><span style="font-weight:900;">[+]</span> Qué sí cubre</div>
                    <ul class="terms-list">
                        @foreach($cert['terms']['covers'] as $item)
                            <li><span class="bullet-covers">+</span> {{ $item }}</li>
                        @endforeach
                    </ul>
                </div>

                <!-- QUE NO CUBRE -->
                <div class="terms-box box-not-covers">
                    <div class="box-not-covers-title"><span style="font-weight:900;">[-]</span> Qué no cubre</div>
                    <ul class="terms-list">
                        @foreach($cert['terms']['not_covers'] as $item)
                            <li><span class="bullet-not-covers">×</span> {{ $item }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <div class="terms-row">
                <!-- COMO RECLAMAR -->
                <div class="terms-box box-claim">
                    <div class="box-claim-title">Pasos para reclamar</div>
                    <ul class="terms-list">
                        @foreach($cert['terms']['claim_steps'] as $idx => $item)
                            <li><span class="bullet-claim">{{ $idx + 1 }}.</span> {{ $item }}</li>
                        @endforeach
                    </ul>
                </div>

                <!-- IMPORTANTE -->
                <div class="terms-box box-important">
                    <div class="box-important-title"><span style="font-weight:900;">[!]</span> Importante</div>
                    <ul class="terms-list">
                        @foreach($cert['terms']['important_notes'] as $item)
                            <li><span class="bullet-important">!</span> {{ $item }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        <!-- SIGNATURE -->
        <div class="signature-block">
            <div class="signature-line">
                <div class="signature-title">Firma del Cliente</div>
                <div class="signature-name">{{ $cert['customer']['name'] }}</div>
                <div style="font-size: 9px; color: #64748b;">C.I. / RIF: {{ $cert['customer']['document'] }}</div>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="cert-footer">
            <div class="footer-left">{{ $cert['tenant']['name'] }} · Certificado de Garantía Oficial</div>
            <div class="footer-center">Documento N° {{ $cert['warranty']['order_id'] }}</div>
            <div class="footer-right">Generado automáticamente</div>
        </div>

    </div>
</body>
</html>
