@echo off
setlocal
title Stop Sonotheque
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\stop-packaged.ps1"
if errorlevel 1 pause
