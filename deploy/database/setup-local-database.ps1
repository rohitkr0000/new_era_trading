<#
  Sets up the LOCAL development database properly (this PC):
    · generates two long random passwords
    · root gets a password (kept in .tools\db-data\root.cnf, readable only by you)
    · creates the dedicated user  neweratrading_app  with rights on the neweratrading database ONLY
    · removes the anonymous / test leftovers
    · points backend\.env at the new user
  The MariaDB of this project (port 3307, started by start-backend.bat) must be running.
  Run:  powershell -ExecutionPolicy Bypass -File deploy\database\setup-local-database.ps1
#>
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$my   = 'C:\xampp\mysql\bin\mysql.exe'
$cnf  = Join-Path $root '.tools\db-data\root.cnf'
$env_ = Join-Path $root 'backend\.env'

function New-Secret([int]$n = 28) { -join ((48..57) + (65..90) + (97..122) | Get-Random -Count $n | ForEach-Object { [char]$_ }) }
function Run-Sql([string]$sql, [bool]$asRoot = $true) {
  $tmp = Join-Path $env:TEMP ('nt_' + [guid]::NewGuid().ToString('N') + '.sql')
  Set-Content -Path $tmp -Value $sql -Encoding utf8
  try {
    $args_ = @('--host=127.0.0.1', '--port=3307', '--connect-timeout=10')
    if (Test-Path $cnf) { $args_ = @("--defaults-extra-file=$cnf") + $args_ } else { $args_ += '--user=root' }
    $out = & $my @args_ --execute="source $($tmp.Replace('\','/'))" 2>&1
    if ($LASTEXITCODE -ne 0) { throw ($out | Out-String) }
    return $out
  } finally { Remove-Item $tmp -Force -ErrorAction SilentlyContinue }
}

"1/5 connecting..."
Run-Sql 'select 1;' | Out-Null

$appPw = New-Secret 28
$template = Get-Content (Join-Path $PSScriptRoot 'setup-database.sql') -Raw
"2/5 creating the database user neweratrading_app (rights on `neweratrading` only)..."
Run-Sql ($template.Replace('__APP_PASSWORD__', $appPw)) | Out-Null

"3/5 setting a root password..."
$rootPw = $null
if (-not (Test-Path $cnf)) {
  $rootPw = New-Secret 28
  Run-Sql ("ALTER USER 'root'@'localhost' IDENTIFIED BY '$rootPw'; ALTER USER 'root'@'127.0.0.1' IDENTIFIED BY '$rootPw'; " +
           "DROP USER IF EXISTS 'root'@'::1'; DROP USER IF EXISTS 'pma'@'localhost'; FLUSH PRIVILEGES;") | Out-Null
  Set-Content -Path $cnf -Value "[client]`nuser=root`npassword=$rootPw`nhost=127.0.0.1`nport=3307`n" -Encoding ascii
  # only you (and SYSTEM) may read the file
  icacls $cnf /inheritance:r /grant:r "$($env:USERNAME):(R,W)" "SYSTEM:(R,W)" | Out-Null
} else { "   (root already has a password - kept)" }

"4/5 pointing backend\.env at the new user..."
$e = Get-Content $env_ -Raw
$e = [regex]::Replace($e, '(?m)^DB_USERNAME=.*$', 'DB_USERNAME=neweratrading_app')
$e = [regex]::Replace($e, '(?m)^DB_PASSWORD=.*$', "DB_PASSWORD=$appPw")
Set-Content -Path $env_ -Value $e -Encoding utf8 -NoNewline

"5/5 verifying..."
$o = & $my --user=neweratrading_app "--password=$appPw" --host=127.0.0.1 --port=3307 --execute="select current_user() as me; show databases; show grants;" 2>&1 | Out-String
$o
$ErrorActionPreference = 'Continue'
$bad = & $my --user=root --host=127.0.0.1 --port=3307 --execute="select 1" 2>&1 | Out-String
"root WITHOUT a password is now refused: " + ($bad -match 'Access denied')
"Done. Root credentials: .tools\db-data\root.cnf   App credentials: backend\.env (DB_USERNAME / DB_PASSWORD)"
