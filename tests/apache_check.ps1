<#
.SYNOPSIS
    Checks the web-server protections of UB-HMS on a real Apache (XAMPP).

.DESCRIPTION
    The PHP built-in server used by e2e.sh ignores .htaccess files, so this script checks what
    Apache itself does: it starts a private Apache on 127.0.0.1:8090 with its own configuration
    (your XAMPP settings are not touched), serves a temporary copy of the application, and
    confirms that internal files are refused, branded error pages appear and the application runs.
    Results are saved to tests\output\apache-results.txt.

.PARAMETER Perf
    After the checks, measure response times with Apache's ab tool for the main pages (one user, then
    ten users at the same time) and save them to tests\output\perf-results.txt. Run tests\perf_seed.php
    on the database first so that the tables hold a realistic volume.

.EXAMPLE
    $env:UBHMS_DB_PORT = '3306'; powershell -ExecutionPolicy Bypass -File tests\apache_check.ps1
#>
[CmdletBinding()]
param(
    [string]$XamppDir = 'C:\xampp',
    [int]   $Port     = 8090,
    [string]$DbPort   = $(if ($env:UBHMS_DB_PORT) { $env:UBHMS_DB_PORT } else { '3306' }),
    [string]$DbName   = $(if ($env:UBHMS_DB_NAME) { $env:UBHMS_DB_NAME } else { 'uhai_bora_hms' }),
    [switch]$Perf
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

    if ($Perf) {
        $ab = Join-Path $XamppDir 'apache\bin\ab.exe'
        if (-not (Test-Path $ab)) { throw "ab.exe not found at $ab" }
        $perfFile = Join-Path $out 'perf-results.txt'
        $cpu = (Get-CimInstance Win32_Processor | Select-Object -First 1)
        $ramGb = [math]::Round((Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory / 1GB, 1)
        $dbVersion = ''
        try { $dbVersion = (& "$XamppDir\mysql\bin\mysql.exe" -h 127.0.0.1 -P $DbPort -u root -N -e 'SELECT VERSION()') } catch { }
        $header = @(
            "UB-HMS response times measured through Apache ($(Get-Date -Format 'yyyy-MM-dd HH:mm'))",
            "Computer: $($cpu.Name.Trim()), $($cpu.NumberOfCores) cores, $ramGb GB RAM; database $dbVersion on port $DbPort, database $DbName",
            "Each row: mean and 95th-percentile time to serve one page, for one user and for ten users working at the same time (each with their own session, no pause between requests); errors = responses that were not 2xx.",
            ''
        )
        Set-Content -Path $perfFile -Value $header -Encoding ascii

        function Sign-In($username) {
            $jarU = Join-Path $work "jar_$username.txt"
            if (Test-Path $jarU) { Remove-Item $jarU }
            $pg = (& curl.exe -s -c $jarU -b $jarU "http://127.0.0.1:$Port/ub-hms/login.php") -join "`n"
            $tok = [regex]::Match($pg, 'name="_csrf" value="([^"]+)"').Groups[1].Value
            & curl.exe -s -o NUL -c $jarU -b $jarU --data-urlencode "_csrf=$tok" --data-urlencode "username=$username" --data-urlencode 'password=UbHms#Demo2026' "http://127.0.0.1:$Port/ub-hms/login.php"
            $line = Get-Content $jarU | Where-Object { $_ -match 'UBHMSSESSID' } | Select-Object -Last 1
            if (-not $line) { throw "Could not sign in as $username" }
            return (($line -split "`t")[-1]).Trim()
        }
        # Ten people work at the same time, each with their own session (one shared session would make
        # PHP queue the requests behind its session lock, which real users do not experience).
        function Measure-Page($label, $username, $path, $requests) {
            $cookies = 1..10 | ForEach-Object { Sign-In $username }
            $cells = @()
            foreach ($users in 1, 10) {
                $each = [math]::Max(4, [int][math]::Ceiling($requests / $users))
                $jobs = @()
                for ($i = 0; $i -lt $users; $i++) {
                    $tsv = Join-Path $work ("ab_{0}_{1}.tsv" -f $users, $i)
                    $txt = Join-Path $work ("ab_{0}_{1}.txt" -f $users, $i)
                    $jobs += [pscustomobject]@{
                        tsv = $tsv; txt = $txt
                        proc = Start-Process -FilePath $ab -ArgumentList '-n', $each, '-c', 1, '-g', $tsv, '-C', "UBHMSSESSID=$($cookies[$i])", "http://127.0.0.1:$Port$path" -NoNewWindow -PassThru -RedirectStandardOutput $txt
                    }
                }
                $jobs.proc | Wait-Process
                $times = @(); $bad = 0
                foreach ($j in $jobs) {
                    $times += Import-Csv -Path $j.tsv -Delimiter "`t" | ForEach-Object { [double]$_.ttime }
                    $m = [regex]::Match((Get-Content $j.txt -Raw), 'Non-2xx responses:\s+(\d+)')
                    if ($m.Success) { $bad += [int]$m.Groups[1].Value }
                }
                $sorted = $times | Sort-Object
                $mean = ($times | Measure-Object -Average).Average
                $p95 = $sorted[[math]::Min($sorted.Count - 1, [int][math]::Floor($sorted.Count * 0.95))]
                $cells += ('{0,6:N0} ms mean {1,6:N0} ms 95th  errors {2}/{3}' -f $mean, $p95, $bad, $sorted.Count)
            }
            $line = '{0,-46} 1 user: {1}   10 users: {2}' -f $label, $cells[0], $cells[1]
            Write-Output $line
            Add-Content -Path $perfFile -Value $line
        }
        $b = "/ub-hms"
        Measure-Page 'PERF-01 Dashboard (receptionist)'              'reception1' "$b/index.php" 100
        Measure-Page 'PERF-02 Patient search by surname (2,000 hits)' 'reception1' "$b/patients/index.php?q=Mutua" 60
        Measure-Page 'PERF-03 Patient search by outpatient number'   'reception1' "$b/patients/index.php?q=OP25-12345" 100
        Measure-Page 'PERF-04 Patient record with history'           'reception1' "$b/patients/view.php?id=5000" 100
        Measure-Page 'PERF-05 Appointment diary (one day)'           'reception1' "$b/appointments/index.php" 100
        Measure-Page 'PERF-06 Visit queue'                           'reception1' "$b/visits/queue.php" 100
        Measure-Page 'PERF-07 Laboratory worklist'                   'lab1'       "$b/lab/index.php" 100
        Measure-Page 'PERF-08 Doctor: my test results'               'doctor1'    "$b/lab/results.php" 100
        Measure-Page 'PERF-09 Medicine stock list'                   'pharmacist1' "$b/pharmacy/stock.php" 100
        Measure-Page 'PERF-10 Invoice search (30,000 invoices)'      'cashier1'   "$b/billing/index.php?q=INV-2025-0123&status=&open=0" 100
        Measure-Page 'PERF-11 Attendance report, 14 days'            'manager1'   "$b/reports/attendance.php" 40
        Measure-Page 'PERF-12 Revenue report, 14 days'               'manager1'   "$b/reports/revenue.php" 40
        Measure-Page 'PERF-13 Revenue report, 20 months'             'manager1'   "$b/reports/revenue.php?from=2025-01-01&to=2026-10-31" 20
        Measure-Page 'PERF-14 Stock report'                          'manager1'   "$b/reports/stock.php" 40
        Measure-Page 'PERF-15 Laboratory report, 20 months'          'manager1'   "$b/reports/lab.php?from=2025-01-01&to=2026-10-31" 20
        Measure-Page 'PERF-16 Audit trail (100,000 entries)'         'manager1'   "$b/admin/audit.php" 60
        Write-Output "Saved to $perfFile"
    }
}
finally {
    if ($proc -and -not $proc.HasExited) { Stop-Process -Id $proc.Id -Force }
    Get-Process httpd -ErrorAction SilentlyContinue | Where-Object { $_.Path -like "$XamppDir*" -and $_.StartTime -gt (Get-Date).AddMinutes(-10) } | Where-Object { (Get-CimInstance Win32_Process -Filter "ProcessId=$($_.Id)").CommandLine -like "*$work*" } | Stop-Process -Force -ErrorAction SilentlyContinue
}
Write-Output ''
Write-Output "Apache checks: $($pass + $fail)   Passed: $pass   Failed: $fail"
Add-Content -Path $resultFile -Value "Totals: $($pass + $fail) run, $pass passed, $fail failed"
if ($fail -gt 0) { exit 1 }
