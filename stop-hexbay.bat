@echo off
setlocal
title Stop Hexbay

taskkill /FI "WINDOWTITLE eq Hexbay PHP API" /T /F >nul 2>&1
taskkill /FI "WINDOWTITLE eq Hexbay React Frontend" /T /F >nul 2>&1
taskkill /FI "WINDOWTITLE eq Hexbay AI Service" /T /F >nul 2>&1

echo Hexbay development servers have been stopped.
timeout /t 2 /nobreak >nul
