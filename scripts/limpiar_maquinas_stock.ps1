param(
    [switch]$Confirmar,
    [string]$SqlScript = "$PSScriptRoot\limpiar_maquinas_stock.sql"
)

$ErrorActionPreference = 'Stop'

$envPath = Join-Path (Split-Path $PSScriptRoot -Parent) '.env'
if (-not (Test-Path -LiteralPath $envPath)) {
    throw "No se encontro .env en $envPath"
}

$cfg = @{}
Get-Content -LiteralPath $envPath | ForEach-Object {
    $line = $_.Trim()
    if ($line -eq '' -or $line.StartsWith('#') -or -not $line.Contains('=')) {
        return
    }

    $parts = $line -split '=', 2
    $cfg[$parts[0].Trim()] = $parts[1].Trim()
}

foreach ($key in @('DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS')) {
    if (-not $cfg.ContainsKey($key) -or [string]::IsNullOrWhiteSpace($cfg[$key])) {
        throw "Falta $key en .env"
    }
}

if (-not (Get-Command sqlcmd -ErrorAction SilentlyContinue)) {
    throw "sqlcmd no esta disponible en PATH"
}

if (-not (Test-Path -LiteralPath $SqlScript)) {
    throw "No se encontro el script SQL en $SqlScript"
}

$scriptParaEjecutar = $SqlScript
if ($Confirmar) {
    $contenido = Get-Content -LiteralPath $SqlScript -Raw
    $contenido = $contenido -replace 'DECLARE @confirmar BIT = 0;', 'DECLARE @confirmar BIT = 1;'
    $scriptParaEjecutar = Join-Path $env:TEMP ('limpiar_maquinas_stock_confirmado_{0}.sql' -f ([guid]::NewGuid()))
    Set-Content -LiteralPath $scriptParaEjecutar -Value $contenido -NoNewline
}

$server = "$($cfg['DB_HOST']),$($cfg['DB_PORT'])"
$modo = if ($Confirmar) { 'BORRADO REAL' } else { 'VISTA PREVIA' }
Write-Host "Ejecutando $modo en $server / $($cfg['DB_NAME'])..."

try {
    & sqlcmd `
        -S $server `
        -d $cfg['DB_NAME'] `
        -U $cfg['DB_USER'] `
        -P $cfg['DB_PASS'] `
        -C `
        -b `
        -i $scriptParaEjecutar

    if ($LASTEXITCODE -ne 0) {
        throw "sqlcmd termino con codigo $LASTEXITCODE"
    }
} finally {
    if ($Confirmar -and $scriptParaEjecutar -ne $SqlScript -and (Test-Path -LiteralPath $scriptParaEjecutar)) {
        Remove-Item -LiteralPath $scriptParaEjecutar -Force
    }
}

Write-Host "Listo."
