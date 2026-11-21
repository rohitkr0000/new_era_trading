<#
  Single-request latency through the private Apache (no concurrency): what does ONE request cost?
    powershell -ExecutionPolicy Bypass -File deploy\load-test\probe-latency.ps1
#>
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force
$srv = Start-Process -FilePath 'C:\xampp\apache\bin\httpd.exe' -ArgumentList '-f', (Join-Path $root '.tools\apache\httpd.conf') -PassThru -WindowStyle Hidden
try {
  for ($i = 0; $i -lt 40; $i++) { Start-Sleep -Milliseconds 500; try { Invoke-WebRequest -UseBasicParsing 'http://127.0.0.1:8091/up' -TimeoutSec 20 | Out-Null; break } catch {} }
  $tok = (Get-Content (Join-Path $root '.tools\load-tokens.txt') | Select-Object -First 1).Trim()
  $h = @{ Authorization = "Bearer $tok"; Accept = 'application/json' }
  function T($name, $sb) { try { & $sb | Out-Null } catch {}; $t = 1..30 | ForEach-Object { (Measure-Command { try { & $sb | Out-Null } catch {} }).TotalMilliseconds } | Sort-Object; "{0,-44} median {1,6:N0} ms   p90 {2,6:N0}   max {3,6:N0}" -f $name, $t[14], $t[26], $t[29] }
  T 'static file  GET /login.html'        { Invoke-WebRequest -UseBasicParsing 'http://127.0.0.1:8091/login.html' -TimeoutSec 20 }
  T 'Laravel boot  GET /up'               { Invoke-WebRequest -UseBasicParsing 'http://127.0.0.1:8091/up' -TimeoutSec 20 }
  T 'API cheap     GET /api/public/sponsor' { Invoke-WebRequest -UseBasicParsing 'http://127.0.0.1:8091/api/public/sponsor/NET1001' -TimeoutSec 20 }
  T 'API           GET /api/public'       { Invoke-WebRequest -UseBasicParsing 'http://127.0.0.1:8091/api/public' -TimeoutSec 20 }
  T 'API signed-in GET /api/bootstrap'    { Invoke-WebRequest -UseBasicParsing 'http://127.0.0.1:8091/api/bootstrap' -Headers $h -TimeoutSec 20 }
} finally { Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force }
