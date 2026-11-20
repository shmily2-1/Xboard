@echo off
chcp 65001 >nul
setlocal
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0windows\api-8380\manage-api.ps1" %*
set "RC=%ERRORLEVEL%"
if not "%~1"=="" pause
exit /b %RC%