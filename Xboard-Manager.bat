@echo off
chcp 65001 >nul
setlocal
cd /d "%~dp0"
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0windows\manage.ps1" %*
set "RC=%ERRORLEVEL%"
if not "%~1"=="" pause
exit /b %RC%
