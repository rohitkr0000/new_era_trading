<#
  Builds a PRIVATE Apache instance for load testing (does not touch XAMPP's own configuration):
    .tools\apache\httpd.conf  – port 8091, document root = backend\public, 128 worker threads (= PHP-FPM pm.max_children: more requests wait in the queue instead of opening 1000 database connections), mod_php with OPcache
    .tools\apache\php\php.ini – XAMPP's php.ini + OPcache on, errors hidden
  Environment of the app under test (database nt_load, production settings) is set with SetEnv, so backend\.env is not read for those keys.
  Start:  C:\xampp\apache\bin\httpd.exe -f D:\neweratrading\.tools\apache\httpd.conf     Stop: taskkill /im httpd.exe /f
#>
param([string]$Port = '8091', [string]$Database = 'nt_load', [int]$Threads = 128)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$dir  = Join-Path $root '.tools\apache'
New-Item -ItemType Directory -Force -Path "$dir\php", "$dir\logs" | Out-Null
$fwd = { param($p) $p.Replace('\', '/') }

# ---- php.ini
$ini = Get-Content 'C:\xampp\php\php.ini' -Raw
$ini = $ini -replace '(?m)^;?\s*zend_extension\s*=\s*opcache.*$', 'zend_extension=opcache'
$ini = $ini -replace '(?m)^;?\s*extension_dir\s*=\s*"\\xampp\\php\\ext".*$', 'extension_dir="C:/xampp/php/ext"'
$ini += @"

; ---- load-test overrides
extension_dir="C:/xampp/php/ext"
display_errors=Off
expose_php=Off
upload_max_filesize=8M
post_max_size=16M
[opcache]
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.jit=off
"@
Set-Content "$dir\php\php.ini" $ini -Encoding ascii

# ---- httpd.conf
$cnf = Get-Content (Join-Path $root '.tools\db-data\root.cnf') -Raw
$rootPw = ([regex]::Match($cnf, '(?m)^password=(.*)$')).Groups[1].Value.Trim()
$public = & $fwd (Join-Path $root 'backend\public')
$conf = @"
ServerRoot "C:/xampp/apache"
Listen 127.0.0.1:$Port
ServerName localhost:$Port
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule log_config_module modules/mod_log_config.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
LoadModule alias_module modules/mod_alias.so
LoadModule setenvif_module modules/mod_setenvif.so
LoadModule env_module modules/mod_env.so
LoadModule php_module "C:/xampp/php/php8apache2_4.dll"
PHPIniDir "$(& $fwd "$dir\php")"
AddHandler application/x-httpd-php .php
TypesConfig "C:/xampp/apache/conf/mime.types"

<IfModule mpm_winnt_module>
  ThreadLimit 2000
  ThreadsPerChild $Threads
  MaxConnectionsPerChild 0
</IfModule>
Timeout 60
KeepAlive Off
ListenBacklog 4096

ErrorLog "$(& $fwd "$dir\logs\error.log")"
LogLevel warn
ServerSignature Off
ServerTokens Prod

DocumentRoot "$public"
DirectoryIndex index.html index.php
<Directory "$public">
  AllowOverride All
  Require all granted
</Directory>

# production-like environment for the app under test
SetEnv APP_ENV production
SetEnv APP_DEBUG false
SetEnv ACCRUE_ON_REQUEST false
SetEnv DEMO_MODE false
SetEnv LOG_CHANNEL errorlog
SetEnv LOG_LEVEL warning
SetEnv CACHE_STORE file
SetEnv APP_CONFIG_CACHE bootstrap/cache/load-config.php
SetEnv APP_ROUTES_CACHE bootstrap/cache/load-routes.php
SetEnv APP_EVENTS_CACHE bootstrap/cache/load-events.php
SetEnv DB_DATABASE $Database
SetEnv DB_USERNAME root
SetEnv DB_PASSWORD $rootPw
"@
Set-Content "$dir\httpd.conf" $conf -Encoding ascii
& 'C:\xampp\apache\bin\httpd.exe' -f "$dir\httpd.conf" -t 2>&1 | Out-String
"config written to $dir"
