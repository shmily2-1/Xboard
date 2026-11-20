@echo off
call "%~dp0Xboard-Manager.bat" -Action Status
exit /b %ERRORLEVEL%
