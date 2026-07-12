@echo off
setlocal
title Sonotheque
powershell.exe -NoProfile -ExecutionPolicy Bypass -STA -File "%~dp0scripts\launch-packaged.ps1"
if errorlevel 1 (
    echo.
    echo Sonotheque could not be started. Review the error above.
    pause
)
