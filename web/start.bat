@echo off
setlocal
cd /d "%~dp0"
set PYTHON=..\phase0\.venv\Scripts\python.exe

if not exist "%PYTHON%" (
    echo [FAIL] Python venv not found: %PYTHON%
    pause
    exit /b 1
)

echo Starting Cognos Web Server on http://127.0.0.1:8765 ...
"%PYTHON%" run.py --open
