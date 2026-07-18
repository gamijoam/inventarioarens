param(
    [ValidateSet('start', 'health', 'test-digital')]
    [string] $Command = 'start',
    [int] $Port = 17777,
    [string] $DigitalDirectory = "$env:USERPROFILE\Desktop\Tickets"
)

$ErrorActionPreference = 'Stop'

function Ensure-Directory([string] $Path) {
    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -ItemType Directory -Path $Path | Out-Null
    }
}

function Write-Json($Context, [int] $StatusCode, $Body) {
    Add-CorsHeaders $Context
    $json = $Body | ConvertTo-Json -Depth 12
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    $Context.Response.StatusCode = $StatusCode
    $Context.Response.ContentType = 'application/json; charset=utf-8'
    $Context.Response.ContentLength64 = $bytes.Length
    $Context.Response.OutputStream.Write($bytes, 0, $bytes.Length)
    $Context.Response.OutputStream.Close()
}

function Add-CorsHeaders($Context) {
    $origin = $Context.Request.Headers['Origin']
    if ([string]::IsNullOrWhiteSpace($origin)) {
        $origin = '*'
    }

    $Context.Response.Headers.Set('Access-Control-Allow-Origin', $origin)
    $Context.Response.Headers.Set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
    $Context.Response.Headers.Set('Access-Control-Allow-Headers', 'Content-Type')
    $Context.Response.Headers.Set('Access-Control-Max-Age', '86400')
}

function Write-Empty($Context, [int] $StatusCode) {
    Add-CorsHeaders $Context
    $Context.Response.StatusCode = $StatusCode
    $Context.Response.ContentLength64 = 0
    $Context.Response.OutputStream.Close()
}

function Resolve-DigitalDirectory($Requested) {
    if ([string]::IsNullOrWhiteSpace($Requested)) {
        return $DigitalDirectory
    }

    if ($Requested.StartsWith('Desktop')) {
        return Join-Path $env:USERPROFILE $Requested
    }

    return $Requested
}

function Save-DigitalTicket($Payload) {
    $station = $Payload.station
    $directory = Resolve-DigitalDirectory $station.digital_directory
    Ensure-Directory $directory

    $tenant = $Payload.payload.tenant.slug
    if ([string]::IsNullOrWhiteSpace($tenant)) { $tenant = 'tenant' }
    $orderId = $Payload.payload.pos_order.id
    if ([string]::IsNullOrWhiteSpace($orderId)) { $orderId = $Payload.job_id }
    $suffix = if ($Payload.payload.copy) { 'copy' } else { 'original' }
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $pdfPath = Join-Path $directory "Ticket-$tenant-$orderId-$stamp-$suffix.pdf"

    if (-not [string]::IsNullOrWhiteSpace($Payload.pdf_base64)) {
        [System.IO.File]::WriteAllBytes($pdfPath, [Convert]::FromBase64String($Payload.pdf_base64))
        return @{ status = 'generated'; pdf_path = $pdfPath }
    }

    $txtPath = [System.IO.Path]::ChangeExtension($pdfPath, '.txt')
    $text = Build-PlainTicket $Payload.payload
    Set-Content -LiteralPath $txtPath -Value $text -Encoding UTF8

    return @{ status = 'generated'; pdf_path = $txtPath; message = 'PDF no recibido; se guardo texto de respaldo.' }
}

function Build-PlainTicket($Ticket) {
    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add($Ticket.tenant.name)
    $lines.Add("Ticket POS #$($Ticket.pos_order.id)")
    $lines.Add("Cliente: $($Ticket.pos_order.customer_name)")
    $lines.Add("--------------------------------")
    foreach ($item in $Ticket.items) {
        $lines.Add($item.product_name)
        $lines.Add("  $($item.quantity) x $($item.unit_price) = $($item.total)")
        foreach ($serial in $item.serials) {
            $lines.Add("  IMEI/Serial: $($serial.serial_number)")
        }
    }
    $lines.Add("--------------------------------")
    $lines.Add("Total USD: $($Ticket.totals.total_base_amount)")
    $lines.Add("Pagado USD: $($Ticket.totals.paid_base_amount)")

    return ($lines -join [Environment]::NewLine)
}

function Print-ThermalTicket($Payload) {
    $station = $Payload.station
    $text = Build-PlainTicket $Payload.payload

    if (-not [string]::IsNullOrWhiteSpace($station.printer_name)) {
        $temp = Join-Path $env:TEMP "ticket-$($Payload.job_id).txt"
        Set-Content -LiteralPath $temp -Value $text -Encoding ASCII
        Get-Content -LiteralPath $temp | Out-Printer -Name $station.printer_name
        Remove-Item -LiteralPath $temp -Force -ErrorAction SilentlyContinue

        return @{ status = 'printed'; message = "Enviado a $($station.printer_name)" }
    }

    $directory = Resolve-DigitalDirectory $station.digital_directory
    Ensure-Directory $directory
    $path = Join-Path $directory "Ticket-$($Payload.job_id)-thermal-preview.txt"
    Set-Content -LiteralPath $path -Value $text -Encoding UTF8

    return @{ status = 'generated'; html_path = $path; message = 'Sin impresora Windows; se guardo vista previa termica.' }
}

function Handle-Print($Context) {
    $reader = New-Object System.IO.StreamReader($Context.Request.InputStream, $Context.Request.ContentEncoding)
    $body = $reader.ReadToEnd()
    $payload = $body | ConvertFrom-Json

    if ($payload.output -eq 'digital') {
        return Save-DigitalTicket $payload
    }

    return Print-ThermalTicket $payload
}

if ($Command -eq 'health') {
    Invoke-RestMethod "http://127.0.0.1:$Port/health"
    exit 0
}

if ($Command -eq 'test-digital') {
    Ensure-Directory $DigitalDirectory
    $path = Join-Path $DigitalDirectory ("Ticket-prueba-" + (Get-Date -Format 'yyyyMMdd-HHmmss') + ".txt")
    Set-Content -LiteralPath $path -Value "Prueba de impresion digital INVENTARIOARENS" -Encoding UTF8
    Write-Host "Archivo generado: $path"
    exit 0
}

Ensure-Directory $DigitalDirectory
$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add("http://127.0.0.1:$Port/")
$listener.Start()
Write-Host "Agente de impresion escuchando en http://127.0.0.1:$Port/"

while ($listener.IsListening) {
    $context = $listener.GetContext()
    try {
        if ($context.Request.HttpMethod -eq 'OPTIONS') {
            Write-Empty $context 204
            continue
        }

        if ($context.Request.HttpMethod -eq 'GET' -and $context.Request.Url.AbsolutePath -eq '/health') {
            Write-Json $context 200 @{ ok = $true; service = 'inventarioarens-printer-agent'; port = $Port }
            continue
        }

        if ($context.Request.HttpMethod -eq 'POST' -and $context.Request.Url.AbsolutePath -eq '/print') {
            Write-Json $context 200 (Handle-Print $context)
            continue
        }

        Write-Json $context 404 @{ ok = $false; message = 'Ruta no encontrada.' }
    } catch {
        Write-Json $context 500 @{ ok = $false; message = $_.Exception.Message }
    }
}
