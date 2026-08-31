@echo off
setlocal EnableExtensions EnableDelayedExpansion
title Set Up Hexbay Intelligence

set "PROJECT_ROOT=%~dp0"
set "AI_ROOT=%PROJECT_ROOT%ai-service"
set "SYSTEM_PYTHON=%LOCALAPPDATA%\Programs\Python\Python312\python.exe"
set "VENV_PYTHON=%AI_ROOT%\.venv\Scripts\python.exe"

if not exist "%SYSTEM_PYTHON%" (
    echo Python 3.12 is not installed.
    echo Install Python 3.12, then run this setup again.
    pause
    exit /b 1
)

if not exist "%VENV_PYTHON%" (
    echo Creating Hexbay's private Python environment...
    "%SYSTEM_PYTHON%" -m venv "%AI_ROOT%\.venv"
    if errorlevel 1 (
        echo The Python environment could not be created.
        pause
        exit /b 1
    )
)

echo Installing the required Python packages...
"%VENV_PYTHON%" -m pip install --disable-pip-version-check -r "%AI_ROOT%\requirements.txt"
if errorlevel 1 (
    echo The Python packages could not be installed.
    pause
    exit /b 1
)

set "EXISTING_SECRET="
if exist "%AI_ROOT%\.env" (
    for /f "tokens=1,* delims==" %%A in ('findstr /B "INTERNAL_SERVICE_SECRET=" "%AI_ROOT%\.env"') do set "EXISTING_SECRET=%%B"
)

if defined EXISTING_SECRET set "AI_SECRET=!EXISTING_SECRET!"

if not defined EXISTING_SECRET (
    for /f %%S in ('powershell -NoProfile -Command "$s=[guid]::NewGuid().ToString() + [guid]::NewGuid().ToString(); $s -replace [char]45"') do set "AI_SECRET=%%S"
    if not defined AI_SECRET (
        echo A private service secret could not be generated.
        pause
        exit /b 1
    )
    (
        echo FLASK_ENV=development
        echo FLASK_HOST=127.0.0.1
        echo FLASK_PORT=5000
        echo INTERNAL_SERVICE_SECRET=!AI_SECRET!
        echo LAPTOP_RECOMMENDER_ALGORITHM=laptop-content-v1.0.0
    ) > "%AI_ROOT%\.env"
)

set "BACKEND_ENV=%PROJECT_ROOT%backend\.env"
if not exist "%BACKEND_ENV%" (
    echo Backend configuration is missing at:
    echo %BACKEND_ENV%
    pause
    exit /b 1
)

powershell -NoProfile -Command "$path='%BACKEND_ENV%'; $lines=Get-Content -LiteralPath $path | Where-Object { $_ -notmatch '^AI_SERVICE_(URL|SECRET|TIMEOUT_SECONDS)=' }; $lines += ''; $lines += 'AI_SERVICE_URL=http://127.0.0.1:5000'; $lines += 'AI_SERVICE_SECRET=!AI_SECRET!'; $lines += 'AI_SERVICE_TIMEOUT_SECONDS=8'; [IO.File]::WriteAllLines($path, $lines)"
if errorlevel 1 (
    echo The PHP-to-Flask service configuration could not be saved.
    pause
    exit /b 1
)

echo.
echo Hexbay intelligence setup is complete.
echo You can now use start-hexbay.bat normally.
timeout /t 3 /nobreak >nul
