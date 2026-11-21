@echo off
rem Runs the plan-logic verification on a throw-away SQLite file (your MariaDB data is never touched).
cd /d "%~dp0.."
set DB_CONNECTION=sqlite
set DB_DATABASE=%CD%\database\verify.sqlite
if exist "%DB_DATABASE%" del "%DB_DATABASE%"
type nul > "%DB_DATABASE%"
"C:\xampp\php\php.exe" artisan migrate:fresh --force >nul
"C:\xampp\php\php.exe" tests\verify_logic.php
del "%DB_DATABASE%" >nul 2>&1
pause
