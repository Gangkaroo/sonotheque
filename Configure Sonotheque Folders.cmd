@echo off
setlocal
title Configure Sonotheque Folders
powershell.exe -NoProfile -ExecutionPolicy Bypass -STA -File "%~dp0scripts\configure-packaged-roots.ps1"
if errorlevel 1 (
    echo.
    echo Sonotheque folders could not be configured. Review the error above.
    pause
)
