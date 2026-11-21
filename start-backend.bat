@echo off
title New Era Trading - backend
rem ------------------------------------------------------------------
rem  Starts everything the website needs on THIS computer (development):
rem    1. MariaDB  - this project's OWN database server, port 3307, data in .tools\db-data
rem                  (separate from XAMPP's MySQL on 3306, so the two can never damage each other)
rem    2. Laravel API        -> http://127.0.0.1:8000
rem    3. Laravel scheduler  -> credits daily ROI, re-checks deposits and backs up the database automatically
rem  Keep this window open while you use the site. Close it to stop the API.
rem ------------------------------------------------------------------
cd /d "%~dp0"

netstat -ano | find ":3307" | find "LISTENING" >nul
if errorlevel 1 (
  echo Starting MariaDB on port 3307...
  start "NET MariaDB" /min "C:\xampp\mysql\bin\mysqld.exe" --defaults-file="%~dp0.tools\db-data\my.ini" --standalone
  timeout /t 6 /nobreak >nul
) else (
  echo MariaDB already running on port 3307.
)

rem make sure the website's database user can reach its database (idempotent; needs the root password file written by deploy\database\setup-local-database.ps1)
if exist "%~dp0.tools\db-data\root.cnf" (
  "C:\xampp\mysql\bin\mysql.exe" --defaults-extra-file="%~dp0.tools\db-data\root.cnf" --connect-timeout=20 --execute="source %~dp0deploy/database/ensure-grants.sql" >nul 2>&1
)

rem Website (static files) on http://localhost:5500 - the pages find the API on port 8000 by themselves
netstat -ano | find ":5500" | find "LISTENING" >nul
if errorlevel 1 start "NET Website" /min "C:\xampp\php\php.exe" -S 127.0.0.1:5500 -t "%~dp0."

cd backend
start "NET Scheduler (daily ROI)" /min "C:\xampp\php\php.exe" artisan schedule:work

echo.
echo  Website:  http://localhost:5500          (open this in your browser)
echo  API:      http://127.0.0.1:8000
echo  Demo logins are shown on the login page (set DEMO_MEMBER_PASSWORD / DEMO_ADMIN_PASSWORD / DEMO_PIN in backend\.env).
echo.
"C:\xampp\php\php.exe" artisan serve --host=127.0.0.1 --port=8000
