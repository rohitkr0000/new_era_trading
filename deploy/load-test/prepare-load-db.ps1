<#
  Builds the load-test database  nt_load  (a COPY with fake members — never your real data):
  demo network + N fake members + 1000 login tokens.   Needs the project's MariaDB running (root.cnf).
  Takes several minutes on Windows because every CREATE TABLE waits for the disk.
    powershell -ExecutionPolicy Bypass -File deploy\load-test\prepare-load-db.ps1 [-Members 5000]
#>
param([int]$Members = 5000, [int]$Tokens = 1000)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$cnf = Join-Path $root '.tools\db-data\root.cnf'
$my = 'C:\xampp\mysql\bin\mysql.exe'
$php = 'C:\xampp\php\php.exe'
$rootPw = ([regex]::Match((Get-Content $cnf -Raw), '(?m)^password=(.*)$')).Groups[1].Value.Trim()

& $my --defaults-extra-file=$cnf --execute="drop database if exists nt_load; create database nt_load character set utf8mb4 collate utf8mb4_unicode_ci;"
$env:DB_DATABASE = 'nt_load'; $env:DB_USERNAME = 'root'; $env:DB_PASSWORD = $rootPw; $env:DB_PORT = '3307'
$env:APP_ENV = 'production'; $env:APP_DEBUG = 'false'; $env:DEMO_MODE = 'true'; $env:ACCRUE_ON_REQUEST = 'false'
Set-Location (Join-Path $root 'backend')
"migrate + demo seed..."
& $php artisan migrate:fresh --seed --force 2>&1 | Select-Object -Last 2
"adding $Members fake members..."
& $php tests\seed_bulk.php $Members
"creating $Tokens tokens..."
& $php tests\make_tokens.php $Tokens (Join-Path $root '.tools\load-tokens.txt')
"ready: database nt_load, tokens in .tools\load-tokens.txt"
