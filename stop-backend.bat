@echo off
rem Stops the Laravel API, the scheduler, the website server and this project's MariaDB (port 3307) started by start-backend.bat.
rem XAMPP's own MySQL (port 3306) is not touched. Always stop the database with this file (a clean shutdown), never by closing its window.
taskkill /fi "windowtitle eq NET Scheduler*" /f >nul 2>&1
taskkill /im php.exe /f >nul 2>&1
if exist "%~dp0.tools\db-data\root.cnf" (
  "C:\xampp\mysql\bin\mysqladmin.exe" --defaults-extra-file="%~dp0.tools\db-data\root.cnf" shutdown >nul 2>&1
) else (
  "C:\xampp\mysql\bin\mysqladmin.exe" --user=root --host=127.0.0.1 --port=3307 shutdown >nul 2>&1
)
echo Backend stopped.
timeout /t 2 >nul
