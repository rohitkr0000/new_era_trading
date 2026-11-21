<#
  Runs the "1000 people online" test against a private Apache (port 8091) and the nt_load database.
  Prepare once:   deploy\load-test\make-apache-config.ps1   and   deploy\load-test\prepare-load-db.ps1
  Run:            powershell -ExecutionPolicy Bypass -File deploy\load-test\run-load-test.ps1 [-Users 1000]
#>
param([int]$Users = 1000, [int]$Seconds = 60)
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$php = 'C:\xampp\php\php.exe'
$httpd = 'C:\xampp\apache\bin\httpd.exe'
$conf = Join-Path $root '.tools\apache\httpd.conf'
$tokens = Join-Path $root '.tools\load-tokens.txt'
Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force
$srv = Start-Process -FilePath $httpd -ArgumentList '-f', $conf -PassThru -WindowStyle Hidden
try {
  for ($i = 0; $i -lt 40; $i++) { Start-Sleep -Milliseconds 500; try { $r = Invoke-WebRequest -UseBasicParsing 'http://127.0.0.1:8091/api/public' -TimeoutSec 20; if ($r.StatusCode -eq 200) { break } } catch {} }
  $r = Invoke-WebRequest -UseBasicParsing 'http://127.0.0.1:8091/api/public' -TimeoutSec 30
  "Apache answers: HTTP $($r.StatusCode), members in the database: $(($r.Content | ConvertFrom-Json).extras.stats.members), Server header: $($r.Headers['Server'])"
  Set-Location (Join-Path $root 'backend')
  "`n######## A. REALISTIC: $Users members online, each opens a page every 15-45 s"
  & $php tests\loadtest_users.php http://127.0.0.1:8091/api $tokens $Users $Seconds 15000 45000
  "`n######## B. BUSY: $Users members online, a request every 3-8 s each"
  & $php tests\loadtest_users.php http://127.0.0.1:8091/api $tokens $Users $Seconds 3000 8000
  "`n######## C. STRESS: $Users members online, everybody reloads every 1-3 s"
  & $php tests\loadtest_users.php http://127.0.0.1:8091/api $tokens $Users $Seconds 1000 3000
} finally {
  Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force
}
