@echo off
setlocal
title Configure Sonotheque Audio Intelligence
powershell.exe -NoProfile -ExecutionPolicy Bypass -STA -File "%~dp0scripts\configure-packaged-audio-intelligence.ps1"
if errorlevel 1 (
    echo.
    echo Sonotheque Audio Intelligence could not be configured. Review the error above.
    pause
)
