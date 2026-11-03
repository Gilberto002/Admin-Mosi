param(
    [string]$SqlScript = "$PSScriptRoot\planchar_stock_pruebas.sql"
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

$server = "$($cfg['DB_HOST']),$($cfg['DB_PORT'])"
Write-Host "Ejecutando planchado de stock en $server / $($cfg['DB_NAME'])..."

& sqlcmd `
    -S $server `
    -d $cfg['DB_NAME'] `
    -U $cfg['DB_USER'] `
    -P $cfg['DB_PASS'] `
    -C `
    -b `
    -i $SqlScript

if ($LASTEXITCODE -ne 0) {
    throw "sqlcmd termino con codigo $LASTEXITCODE"
}

Write-Host "Listo."
