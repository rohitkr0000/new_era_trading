<#
  Brings the existing nt_load database up to date after a code change (new migrations, team stats, fresh random tokens)
  without rebuilding it:   powershell -ExecutionPolicy Bypass -File deploy\load-test\refresh-load-db.ps1
#>
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$cnf = Join-Path $root '.tools\db-data\root.cnf'
$php = 'C:\xampp\php\php.exe'
$rootPw = ([regex]::Match((Get-Content $cnf -Raw), '(?m)^password=(.*)$')).Groups[1].Value.Trim()
$env:DB_DATABASE = 'nt_load'; $env:DB_USERNAME = 'root'; $env:DB_PASSWORD = $rootPw; $env:DB_PORT = '3307'
$env:APP_ENV = 'production'; $env:APP_DEBUG = 'false'; $env:CACHE_STORE = 'file'
Set-Location (Join-Path $root 'backend')
& $php artisan migrate --force 2>&1 | Select-Object -Last 3
& $php artisan cache:clear 2>&1 | Select-Object -Last 1
& $php artisan net:stats 2>&1 | Select-Object -Last 1
# production-style caches, written to their OWN files so the normal dev server is not affected
$env:APP_CONFIG_CACHE = 'bootstrap/cache/load-config.php'; $env:APP_ROUTES_CACHE = 'bootstrap/cache/load-routes.php'; $env:APP_EVENTS_CACHE = 'bootstrap/cache/load-events.php'   # relative to backend/, as Laravel expects
$env:DB_DATABASE = 'nt_load'; $env:DB_USERNAME = 'root'; $env:DB_PASSWORD = $rootPw; $env:DEMO_MODE = 'false'; $env:ACCRUE_ON_REQUEST = 'false'; $env:LOG_CHANNEL = 'errorlog'; $env:LOG_LEVEL = 'warning'
& $php artisan config:cache 2>&1 | Select-Object -Last 1
& $php artisan route:cache 2>&1 | Select-Object -Last 1
& $php artisan event:cache 2>&1 | Select-Object -Last 1
Remove-Item Env:\APP_CONFIG_CACHE, Env:\APP_ROUTES_CACHE, Env:\APP_EVENTS_CACHE -ErrorAction SilentlyContinue
Remove-Item (Join-Path $root '.tools\load-tokens.txt') -ErrorAction SilentlyContinue
& $php tests\make_tokens.php 1000 (Join-Path $root '.tools\load-tokens.txt')
