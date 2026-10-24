<#
.SYNOPSIS
    Back up the UB-HMS database to a dated file.

.DESCRIPTION
    Uses mysqldump (from XAMPP by default) to write a consistent copy of the whole
    database to storage\backups, checks the file is complete, compresses it, and deletes
    backups older than KeepDays. The database password is read from the UBHMS_DB_PASS
    environment variable (or -DbPassword) and is passed through a temporary file, never on
    the command line.

    Schedule it every night, for example:
      schtasks /Create /SC DAILY /ST 22:00 /TN "UB-HMS backup" /TR "powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\ub-hms\scripts\backup.ps1"

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\backup.ps1
#>
[CmdletBinding()]
param(
    [string]$DbName     = $(if ($env:UBHMS_DB_NAME) { $env:UBHMS_DB_NAME } else { 'uhai_bora_hms' }),
    [string]$DbHost     = $(if ($env:UBHMS_DB_HOST) { $env:UBHMS_DB_HOST } else { '127.0.0.1' }),
    [int]   $DbPort     = $(if ($env:UBHMS_DB_PORT) { [int]$env:UBHMS_DB_PORT } else { 3306 }),
    [string]$DbUser     = $(if ($env:UBHMS_DB_USER) { $env:UBHMS_DB_USER } else { 'root' }),
    [string]$DbPassword = $(if ($env:UBHMS_DB_PASS) { $env:UBHMS_DB_PASS } else { '' }),
    [string]$MysqlBin   = 'C:\xampp\mysql\bin',
    [string]$OutDir     = '',
    [int]   $KeepDays   = 30
)

$ErrorActionPreference = 'Stop'
if (-not $OutDir) { $OutDir = Join-Path (Split-Path $PSScriptRoot -Parent) 'storage\backups' }
$dump = Join-Path $MysqlBin 'mysqldump.exe'
if (-not (Test-Path $dump)) { throw "mysqldump not found at $dump. Set -MysqlBin to the folder that contains it." }
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$sqlFile = Join-Path $OutDir "$DbName`_$stamp.sql"
$cnf = Join-Path ([IO.Path]::GetTempPath()) ("ubhms_" + [guid]::NewGuid().ToString('N') + '.cnf')

try {
    # Credentials go in a temporary file that only this user can read.
    Set-Content -Path $cnf -Value "[client]`nuser=$DbUser`npassword=$DbPassword`nhost=$DbHost`nport=$DbPort" -Encoding ascii
    & icacls $cnf /inheritance:r /grant:r "$($env:USERNAME):(R,W)" | Out-Null

    & $dump "--defaults-extra-file=$cnf" --single-transaction --routines --triggers --default-character-set=utf8mb4 --result-file=$sqlFile $DbName
    if ($LASTEXITCODE -ne 0) { throw "mysqldump failed with exit code $LASTEXITCODE." }
} finally {
    Remove-Item $cnf -Force -ErrorAction SilentlyContinue
}

# A complete dump ends with a "Dump completed" line.
$tail = Get-Content $sqlFile -Tail 3 -ErrorAction Stop
if (-not ($tail -match 'Dump completed')) {
    Remove-Item $sqlFile -Force
    throw 'The backup file is incomplete and was removed.'
}

$zip = "$sqlFile.zip"
Compress-Archive -Path $sqlFile -DestinationPath $zip -Force
Remove-Item $sqlFile -Force

# Remove backups older than KeepDays (never the one just made).
Get-ChildItem $OutDir -Filter "$DbName`_*.sql.zip" |
    Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$KeepDays) -and $_.FullName -ne $zip } |
    Remove-Item -Force

$size = [math]::Round((Get-Item $zip).Length / 1KB, 1)
$line = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') backup ok: $(Split-Path $zip -Leaf) ($size KB)"
Add-Content -Path (Join-Path $OutDir 'backup.log') -Value $line
Write-Output $line
