<#
  Builds a ready-to-upload release folder:  release\neweratrading\
    backend\            Laravel app (with vendor\, without .env / tests / logs / local data)
    backend\public\     = the web root: the Laravel entry point AND the website (index.html, user\, admin\, css\, js ...)

  Usage (PowerShell, from the project folder):   .\deploy\build-release.ps1
  Then upload release\neweratrading\ to the server and follow DEPLOY.md.
#>
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$out  = Join-Path $root 'release\neweratrading'
if (Test-Path $out) { Remove-Item $out -Recurse -Force }
New-Item -ItemType Directory -Force -Path "$out\backend" | Out-Null

Write-Host '1/4 copying the Laravel app...'
$skipDirs = @('tests', 'node_modules', '.git', '.idea', '.vscode')
# robocopy only matches excluded folders by FULL path (a relative 'storage\app' silently excludes nothing)
$skipFull = 'storage\logs', 'storage\framework\cache', 'storage\framework\sessions', 'storage\framework\views', 'storage\app', 'bootstrap\cache' | ForEach-Object { Join-Path "$root\backend" $_ }
robocopy "$root\backend" "$out\backend" /E /NFL /NDL /NJH /NJS /NP /R:1 /W:1 /MT:8 /XD $skipDirs $skipFull /XF '.env' '*.sqlite' '*.log' '*.sql.gz' '*.zip' 'phpunit.xml' | Out-Null; if ($LASTEXITCODE -ge 8) { Write-Warning "robocopy reported errors (exit $LASTEXITCODE) - a file may be locked by another program" }
foreach ($d in 'storage\logs', 'storage\framework\cache\data', 'storage\framework\sessions', 'storage\framework\views', 'storage\app\private', 'storage\app\public', 'storage\app\backups', 'bootstrap\cache') {
  New-Item -ItemType Directory -Force -Path "$out\backend\$d" | Out-Null
}
Get-ChildItem "$out\backend\bootstrap\cache" -File -ErrorAction SilentlyContinue | Remove-Item -Force   # stale cached config from this PC

Write-Host '2/4 copying the website into backend\public ...'
$pub = "$out\backend\public"
foreach ($f in 'index.html', 'login.html', 'register.html', 'forgot.html', 'calculator.html') { Copy-Item "$root\$f" $pub -Force }
foreach ($d in 'css', 'fonts', 'images', 'user', 'admin') { robocopy "$root\$d" "$pub\$d" /E /NFL /NDL /NJH /NJS /NP /R:1 /W:1 /MT:8 | Out-Null }
New-Item -ItemType Directory -Force -Path "$pub\js" | Out-Null
robocopy "$root\js" "$pub\js" /E /NFL /NDL /NJH /NJS /NP /R:1 /W:1 /MT:8 /XF 'data-local.js' | Out-Null; if ($LASTEXITCODE -ge 8) { Write-Warning "robocopy reported errors (exit $LASTEXITCODE) - a file may be locked by another program" }   # data-local.js was the old offline demo store

Write-Host '3/4 checking nothing secret is inside...'
$bad = Get-ChildItem $out -Recurse -Force -File | Where-Object {
  $_.Name -in '.env', 'laravel.log' -or $_.Extension -in '.sqlite', '.gz' -or                              # env, logs, databases, backups
  ($_.Extension -eq '.sql' -and $_.FullName -notlike '*\vendor\*') -or                                     # a database dump outside vendor (packages ship harmless .sql helpers)
  ($_.FullName -like '*\storage\app\*' -and $_.Name -ne '.gitignore')                                      # nothing at all may ship inside storage\app (KYC files, backups)
}
if ($bad) { $bad | ForEach-Object { Write-Warning "unexpected file: $($_.FullName)" }; throw 'Release contains local files.' }
if (Select-String -Path "$pub\*.html", "$pub\js\*.js" -Pattern '127\.0\.0\.1:8000' -SimpleMatch -List | Where-Object { $_.Path -notmatch 'data\.js$' }) { Write-Warning 'a page still points to 127.0.0.1:8000' }

Write-Host '4/4 zipping...'
$zip = Join-Path $root 'release\neweratrading.zip'
if (Test-Path $zip) { Remove-Item $zip -Force }
Compress-Archive -Path $out -DestinationPath $zip -CompressionLevel Optimal
'{0:N1} MB  ->  {1}' -f ((Get-Item $zip).Length / 1MB), $zip
Write-Host 'Done. Next: DEPLOY.md'

