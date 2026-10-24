<#
.SYNOPSIS
    Checks the web-server protections of UB-HMS on a real Apache (XAMPP).

.DESCRIPTION
    The PHP built-in server used by e2e.sh ignores .htaccess files, so this script checks what
    Apache itself does: it starts a private Apache on 127.0.0.1:8090 with its own configuration
    (your XAMPP settings are not touched), serves a temporary copy of the application, and
    confirms that internal files are refused, branded error pages appear and the application runs.
    Results are saved to tests\output\apache-results.txt.

.EXAMPLE
    $env:UBHMS_DB_PORT = '3306'; powershell -ExecutionPolicy Bypass -File tests\apache_check.ps1
#>
[CmdletBinding()]
param(
    [string]$XamppDir = 'C:\xampp',
    [int]   $Port     = 8090,
    [string]$DbPort   = $(if ($env:UBHMS_DB_PORT) { $env:UBHMS_DB_PORT } else { '3306' }),
    [string]$DbName   = $(if ($env:UBHMS_DB_NAME) { $env:UBHMS_DB_NAME } else { 'uhai_bora_hms' })
)

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$work = Join-Path ([IO.Path]::GetTempPath()) 'ubhms_apache'
$docroot = Join-Path $work 'htdocs'
$app = Join-Path $docroot 'ub-hms'
$httpd = Join-Path $XamppDir 'apache\bin\httpd.exe'
if (-not (Test-Path $httpd)) { throw "Apache not found at $httpd" }

if (Test-Path $work) { Remove-Item $work -Recurse -Force }
New-Item -ItemType Directory -Force -Path $app | Out-Null
robocopy $root $app /E /XD .git output backups logs tmp /XF *.zip /NFL /NDL /NJH /NJS /NP | Out-Null
foreach ($d in 'storage\logs', 'storage\backups', 'storage\tmp') { New-Item -ItemType Directory -Force -Path (Join-Path $app $d) | Out-Null }
Set-Content -Path (Join-Path $app 'storage\logs\app.log') -Value 'secret log line' -Encoding ascii
Set-Content -Path (Join-Path $app 'config\config.local.php') -Value "<?php return ['db' => ['port' => '$DbPort', 'name' => '$DbName']];" -Encoding ascii

$fwd = $work -replace '\\', '/'
$xfwd = $XamppDir -replace '\\', '/'
$conf = @"
ServerRoot "$xfwd/apache"
Listen 127.0.0.1:$Port
ServerName localhost:$Port
PidFile "$fwd/httpd.pid"
ErrorLog "$fwd/error.log"
LogLevel warn
LoadModule access_compat_module modules/mod_access_compat.so
LoadModule alias_module modules/mod_alias.so
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule dir_module modules/mod_dir.so
LoadModule env_module modules/mod_env.so
LoadModule headers_module modules/mod_headers.so
LoadModule log_config_module modules/mod_log_config.so
LoadModule mime_module modules/mod_mime.so
LoadModule setenvif_module modules/mod_setenvif.so
LoadFile "$xfwd/php/php8ts.dll"
LoadModule php_module "$xfwd/php/php8apache2_4.dll"
PHPINIDir "$xfwd/php"
TypesConfig "$xfwd/apache/conf/mime.types"
DocumentRoot "$fwd/htdocs"
<Directory />
    AllowOverride None
    Require all denied
</Directory>
<Directory "$fwd/htdocs">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
DirectoryIndex index.php
"@
Set-Content -Path (Join-Path $work 'httpd.conf') -Value $conf -Encoding ascii

$out = Join-Path $root 'tests\output'
New-Item -ItemType Directory -Force -Path $out | Out-Null
$resultFile = Join-Path $out 'apache-results.txt'
Set-Content -Path $resultFile -Value '' -Encoding ascii
$pass = 0; $fail = 0

function Record($ok, $id, $text) {
    $line = '{0}  {1}  {2}' -f $(if ($ok) { 'PASS' } else { 'FAIL' }), $id, $text
    Write-Output $line
    Add-Content -Path $resultFile -Value $line
    if ($ok) { $script:pass++ } else { $script:fail++ }
}
function Status($path, $extra = @()) { (& curl.exe -s -o NUL -w '%{http_code}' @extra "http://127.0.0.1:$Port$path") }

$proc = Start-Process -FilePath $httpd -ArgumentList '-f', (Join-Path $work 'httpd.conf') -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $work 'start.err')
try {
    $up = $false
    for ($i = 0; $i -lt 40; $i++) {
        Start-Sleep -Milliseconds 500
        if ((Status '/ub-hms/login.php') -eq '200') { $up = $true; break }
    }
    if (-not $up) { Get-Content (Join-Path $work 'start.err'), (Join-Path $work 'error.log') -ErrorAction SilentlyContinue | Select-Object -Last 15; throw 'Apache did not start.' }

    Record ((Status '/ub-hms/login.php') -eq '200') 'APA-001' 'The sign-in page is served by Apache and PHP'
    foreach ($p in @(
        @('APA-002', '/ub-hms/database/schema.sql', 'The database schema file is refused (403)'),
        @('APA-003', '/ub-hms/database/install.php', 'The installer script is refused (403)'),
        @('APA-004', '/ub-hms/includes/auth.php', 'Application code in includes/ is refused (403)'),
        @('APA-005', '/ub-hms/config/config.php', 'The configuration file is refused (403)'),
        @('APA-006', '/ub-hms/config/config.local.php', 'The local configuration file is refused (403)'),
        @('APA-007', '/ub-hms/tests/run_tests.php', 'The test runner is refused (403)'),
        @('APA-008', '/ub-hms/tests/e2e.sh', 'The test script is refused (403)'),
        @('APA-009', '/ub-hms/storage/logs/app.log', 'The application log is refused (403)'),
        @('APA-010', '/ub-hms/storage/backups/', 'The backups folder is refused (403)'),
        @('APA-011', '/ub-hms/docs/', 'The documentation folder is refused (403)'),
        @('APA-012', '/ub-hms/scripts/backup.ps1', 'The backup script is refused (403)'),
        @('APA-013', '/ub-hms/README.md', 'Markdown and other internal file types are refused (403)'),
        @('APA-014', '/ub-hms/.gitignore', 'Dot-files are refused (403)')
    )) { $got = Status $p[1]; Record ($got -eq '403') $p[0] ($p[2] + $(if ($got -ne '403') { " (got $got)" } else { '' })) }

    $listing = (& curl.exe -s "http://127.0.0.1:$Port/ub-hms/assets/") -join "`n"
    $listStatus = Status '/ub-hms/assets/'
    Record (($listStatus -in '403', '404') -and ($listing -notmatch 'Index of') -and ($listing -notmatch 'Parent Directory')) 'APA-015' "Directory listing is switched off: a folder URL shows no file list (got $listStatus)"
    Record ((Status '/ub-hms/assets/css/app.css') -eq '200') 'APA-016' 'The stylesheet is served'
    Record ((Status '/ub-hms/assets/js/app.js') -eq '200') 'APA-017' 'The script file is served'
    $nf = (& curl.exe -s "http://127.0.0.1:$Port/ub-hms/no-such-page.php")
    Record (($nf -match 'Page not found') -and ($nf -notmatch 'Apache') -and ($nf -notmatch 'xampp')) 'APA-018' 'A missing page shows the branded error page, not the server default'
    $h = (& curl.exe -s -D - -o NUL "http://127.0.0.1:$Port/ub-hms/login.php") -join "`n"
    Record (($h -match 'Content-Security-Policy') -and ($h -match 'X-Frame-Options') -and ($h -notmatch 'X-Powered-By')) 'APA-019' 'Security headers are sent and the PHP version is not advertised'
    Record (($h -match 'HttpOnly') -and ($h -match 'SameSite=Strict')) 'APA-020' 'The session cookie is HttpOnly and SameSite=Strict'
    Record ((Status '/ub-hms/.git/config') -in '403', '404') 'APA-021' 'A .git folder would not be served (403/404)'

    # the application itself, end to end through Apache
    $jar = Join-Path $work 'jar.txt'
    $login = (& curl.exe -s -c $jar -b $jar "http://127.0.0.1:$Port/ub-hms/login.php") -join "`n"
    $m = [regex]::Match($login, 'name="_csrf" value="([^"]+)"')
    $code = & curl.exe -s -o NUL -w '%{http_code}' -c $jar -b $jar --data-urlencode "_csrf=$($m.Groups[1].Value)" --data-urlencode 'username=doctor1' --data-urlencode 'password=UbHms#Demo2026' "http://127.0.0.1:$Port/ub-hms/login.php"
    $dash = (& curl.exe -s -c $jar -b $jar "http://127.0.0.1:$Port/ub-hms/index.php") -join "`n"
    Record (($code -eq '303') -and ($dash -match 'Welcome')) 'APA-022' 'A demonstration doctor can sign in and open the dashboard through Apache'
    $denied = & curl.exe -s -o NUL -w '%{http_code}' -b $jar "http://127.0.0.1:$Port/ub-hms/admin/users.php"
    Record ($denied -eq '403') 'APA-023' 'Role-based access works through Apache: a doctor is refused the user accounts page (403)'
}
finally {
    if ($proc -and -not $proc.HasExited) { Stop-Process -Id $proc.Id -Force }
    Get-Process httpd -ErrorAction SilentlyContinue | Where-Object { $_.Path -like "$XamppDir*" -and $_.StartTime -gt (Get-Date).AddMinutes(-10) } | Where-Object { (Get-CimInstance Win32_Process -Filter "ProcessId=$($_.Id)").CommandLine -like "*$work*" } | Stop-Process -Force -ErrorAction SilentlyContinue
}
Write-Output ''
Write-Output "Apache checks: $($pass + $fail)   Passed: $pass   Failed: $fail"
Add-Content -Path $resultFile -Value "Totals: $($pass + $fail) run, $pass passed, $fail failed"
if ($fail -gt 0) { exit 1 }
