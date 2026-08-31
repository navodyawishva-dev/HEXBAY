@echo off
setlocal
title Hexbay Launcher

set "PROJECT_ROOT=%~dp0"
set "PHP_EXE=C:\wamp64\bin\php\php8.3.6\php.exe"
set "MYSQLADMIN_EXE=C:\wamp64\bin\mysql\mysql8.3.0\bin\mysqladmin.exe"
set "NODE_DIR=C:\Program Files\nodejs"
set "AI_PYTHON=%PROJECT_ROOT%ai-service\.venv\Scripts\python.exe"

if not exist "%PHP_EXE%" (
    echo Hexbay could not find WAMP PHP at:
    echo %PHP_EXE%
    pause
    exit /b 1
)

if not exist "%NODE_DIR%\node.exe" (
    echo Hexbay could not find Node.js. Please reinstall Node.js LTS.
    pause
    exit /b 1
)

if not exist "%AI_PYTHON%" (
    echo Hexbay could not find its Python intelligence environment.
    echo Double-click setup-hexbay-ai.bat once, then try again.
    pause
    exit /b 1
)

if not exist "%PROJECT_ROOT%ai-service\.env" (
    echo Hexbay intelligence configuration is missing.
    echo Double-click setup-hexbay-ai.bat once, then try again.
    pause
    exit /b 1
)

"%MYSQLADMIN_EXE%" --host=127.0.0.1 --port=3306 --user=root ping >nul 2>&1
if errorlevel 1 (
    echo MySQL is not responding. Open Wampserver and wait for the green icon.
    pause
    exit /b 1
)

curl.exe --silent --fail "http://127.0.0.1:8080/api/v1/health" >nul 2>&1
set "HEXBAY_API_RUNNING=%ERRORLEVEL%"
curl.exe --silent --fail "http://127.0.0.1:5173" >nul 2>&1
set "HEXBAY_FRONTEND_RUNNING=%ERRORLEVEL%"
curl.exe --silent --fail "http://127.0.0.1:5000/internal/health" >nul 2>&1
set "HEXBAY_AI_RUNNING=%ERRORLEVEL%"

if "%HEXBAY_API_RUNNING%"=="0" if "%HEXBAY_FRONTEND_RUNNING%"=="0" if "%HEXBAY_AI_RUNNING%"=="0" (
    echo Hexbay is already running. Opening it in your browser...
    start "" "http://127.0.0.1:5173"
    timeout /t 2 /nobreak >nul
    exit /b 0
)

if not "%HEXBAY_API_RUNNING%"=="0" (
    netstat -ano | findstr /R /C:":8080 .*LISTENING" >nul
    if not errorlevel 1 (
        echo Port 8080 is being used by another program.
        echo Close that program or restart your computer, then try again.
        pause
        exit /b 1
    )
    start "Hexbay PHP API" /D "%PROJECT_ROOT%" /min "%PHP_EXE%" -d xdebug.mode=off -d upload_max_filesize=8M -d post_max_size=10M -S 127.0.0.1:8080 -t "%PROJECT_ROOT%backend\public"
)

if not "%HEXBAY_FRONTEND_RUNNING%"=="0" (
    netstat -ano | findstr /R /C:":5173 .*LISTENING" >nul
    if not errorlevel 1 (
        echo Port 5173 is being used by another program.
        echo Close that program or restart your computer, then try again.
        pause
        exit /b 1
    )
    start "Hexbay React Frontend" /D "%PROJECT_ROOT%frontend" /min "%NODE_DIR%\node.exe" "%PROJECT_ROOT%frontend\node_modules\vite\bin\vite.js" --host 127.0.0.1 --port 5173
)

if not "%HEXBAY_AI_RUNNING%"=="0" (
    netstat -ano | findstr /R /C:":5000 .*LISTENING" >nul
    if not errorlevel 1 (
        echo Port 5000 is being used by another program.
        echo Close that program or restart your computer, then try again.
        pause
        exit /b 1
    )
    start "Hexbay AI Service" /D "%PROJECT_ROOT%ai-service" /min "%AI_PYTHON%" "%PROJECT_ROOT%ai-service\app.py"
)

echo Starting Hexbay...
timeout /t 6 /nobreak >nul
start "" "http://127.0.0.1:5173"
echo Hexbay is opening in your browser.
echo Use stop-hexbay.bat when you finish.
timeout /t 3 /nobreak >nul
