@echo off
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0windows\cap\manage-cap.ps1" -Action Menu
if errorlevel 1 pause
