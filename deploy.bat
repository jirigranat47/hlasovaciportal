@echo off
echo ========================================
echo    FTP Deployment - Hlasovaci Portal
echo ========================================
echo.

powershell.exe -ExecutionPolicy Bypass -File "%~dp0deploy.ps1"

echo.
echo ========================================
pause
