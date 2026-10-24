<#
.SYNOPSIS
    Restore the UB-HMS database from a backup made by backup.ps1.

.DESCRIPTION
    Replaces the tables in the database with the contents of the backup file. Before it does
    anything it makes a safety backup of the current data (so a wrong restore can be undone) and
    asks you to type the database name to confirm. Stop users from working first.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\restore.ps1 -BackupFile storage\backups\uhai_bora_hms_20261215_220000.sql.zip
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$BackupFile,
    [string]$DbName     = $(if ($env:UBHMS_DB_NAME) { $env:UBHMS_DB_NAME } else { 'uhai_bora_hms' }),
    [string]$DbHost     = $(if ($env:UBHMS_DB_HOST) { $env:UBHMS_DB_HOST } else { '127.0.0.1' }),
    [int]   $DbPort     = $(if ($env:UBHMS_DB_PORT) { [int]$env:UBHMS_DB_PORT } else { 3306 }),
    [string]$DbUser     = $(if ($env:UBHMS_DB_USER) { $env:UBHMS_DB_USER } else { 'root' }),
    [string]$DbPassword = $(if ($env:UBHMS_DB_PASS) { $env:UBHMS_DB_PASS } else { '' }),
    [string]$MysqlBin   = 'C:\xampp\mysql\bin',
    [switch]$Yes
)

$ErrorActionPreference = 'Stop'
if (-not (Test-Path $BackupFile)) { throw "Backup file not found: $BackupFile" }
$mysql = Join-Path $MysqlBin 'mysql.exe'
if (-not (Test-Path $mysql)) { throw "mysql.exe not found at $mysql." }

if (-not $Yes) {
    Write-Output "This will REPLACE all data in database '$DbName' with the contents of:"
    Write-Output "  $BackupFile"
    $answer = Read-Host "Type the database name ($DbName) to continue"
    if ($answer -ne $DbName) { Write-Output 'Cancelled. Nothing was changed.'; exit 1 }
}

# 1. Safety backup of what is there now
& (Join-Path $PSScriptRoot 'backup.ps1') -DbName $DbName -DbHost $DbHost -DbPort $DbPort -DbUser $DbUser -DbPassword $DbPassword -MysqlBin $MysqlBin | Out-Null
Write-Output 'Safety backup of the current data made.'

# 2. Unzip if needed
$work = $BackupFile
$temp = $null
if ($BackupFile -like '*.zip') {
    $temp = Join-Path ([IO.Path]::GetTempPath()) ("ubhms_restore_" + [guid]::NewGuid().ToString('N'))
    Expand-Archive -Path $BackupFile -DestinationPath $temp -Force
    $work = (Get-ChildItem $temp -Filter *.sql | Select-Object -First 1).FullName
}

$cnf = Join-Path ([IO.Path]::GetTempPath()) ("ubhms_" + [guid]::NewGuid().ToString('N') + '.cnf')
try {
    Set-Content -Path $cnf -Value "[client]`nuser=$DbUser`npassword=$DbPassword`nhost=$DbHost`nport=$DbPort" -Encoding ascii
    & icacls $cnf /inheritance:r /grant:r "$($env:USERNAME):(R,W)" | Out-Null
    & $mysql "--defaults-extra-file=$cnf" --default-character-set=utf8mb4 -e "CREATE DATABASE IF NOT EXISTS ``$DbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    Get-Content $work -Raw -Encoding UTF8 | & $mysql "--defaults-extra-file=$cnf" --default-character-set=utf8mb4 $DbName
    if ($LASTEXITCODE -ne 0) { throw "Restore failed with exit code $LASTEXITCODE. The safety backup is in storage\backups." }
} finally {
    Remove-Item $cnf -Force -ErrorAction SilentlyContinue
    if ($temp) { Remove-Item $temp -Recurse -Force -ErrorAction SilentlyContinue }
}
Write-Output "Restore complete. Database '$DbName' now matches the backup."
