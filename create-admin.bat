@echo off
setlocal
title Create Hexbay Administrator

set "PROJECT_ROOT=%~dp0"
set "PHP_EXE=C:\wamp64\bin\php\php8.3.6\php.exe"
set "MYSQLADMIN_EXE=C:\wamp64\bin\mysql\mysql8.3.0\bin\mysqladmin.exe"

if not exist "%PHP_EXE%" (
    echo Hexbay could not find WAMP PHP at:
    echo %PHP_EXE%
    pause
    exit /b 1
)

"%MYSQLADMIN_EXE%" --host=127.0.0.1 --port=3306 --user=root ping >nul 2>&1
if errorlevel 1 (
    echo MySQL is not responding. Open Wampserver and wait for the green icon.
    pause
    exit /b 1
)

echo.
echo Create the private administrator account used at /admin/login.
echo The password must have 10 or more characters, uppercase, lowercase and a number.
echo.
"%PHP_EXE%" -d xdebug.mode=off "%PROJECT_ROOT%backend\scripts\create_admin.php"

echo.
pause
