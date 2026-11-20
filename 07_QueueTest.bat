@echo off
call "%~dp0Xboard-Manager.bat" -Action Test
exit /b %ERRORLEVEL%
