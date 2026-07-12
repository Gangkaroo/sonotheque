@echo off
setlocal
title Sonotheque Status
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\status-packaged.ps1"
echo.
pause
