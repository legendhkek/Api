@echo off
title Shopify API Server - Starting...
color 0A

echo ╔══════════════════════════════════════════════════════════╗
echo ║     SHOPIFY API SERVER - DEPLOYMENT SCRIPT              ║
echo ╚══════════════════════════════════════════════════════════╝
echo.

REM Find PHP executable (prefer one with cURL enabled)
set PHP_PATH=

set "CANDIDATE_1=C:\xampp\php\php.exe"
set "CANDIDATE_2=C:\wamp64\bin\php\php8.2.12\php.exe"
set "CANDIDATE_3=C:\php\php.exe"

for %%P in ("%CANDIDATE_1%" "%CANDIDATE_2%" "%CANDIDATE_3%") do (
    if exist %%~fP (
        set "PHP_PATH=%%~fP"
        goto :CheckCurl
    )
)

echo [ERROR] PHP not found in common locations!
echo Please install XAMPP or WAMP, or update the PHP_PATH in this script.
pause
exit /b 1

:CheckCurl
echo.
echo Checking PHP extensions for cURL support...
"%PHP_PATH%" -m | findstr /I "curl" >nul 2>&1
if errorlevel 1 (
    echo [WARN] Selected PHP does NOT have cURL enabled: %PHP_PATH%
    echo        Attempting alternate PHP locations...
    set "PHP_WITH_CURL="
    for %%Q in ("%CANDIDATE_1%" "%CANDIDATE_2%" "%CANDIDATE_3%") do (
        if exist %%~fQ (
            "%%~fQ" -m | findstr /I "curl" >nul 2>&1
            if not errorlevel 1 (
                set "PHP_WITH_CURL=%%~fQ"
                goto :FoundCurl
            )
        )
    )
    if not defined PHP_WITH_CURL (
        echo [ERROR] No PHP with cURL found. Please enable the cURL extension in php.ini.
        echo        Tip: If you have XAMPP installed, use: C:\xampp\php\php.ini and ensure: extension=curl
        pause
        exit /b 1
    )
)
goto :Continue

:FoundCurl
set "PHP_PATH=%PHP_WITH_CURL%"
echo [OK] Using PHP with cURL: %PHP_PATH%

:Continue
echo [OK] PHP executable: %PHP_PATH%

echo.
echo ╔══════════════════════════════════════════════════════════╗
echo ║                    SERVER INFORMATION                    ║
echo ╚══════════════════════════════════════════════════════════╝
echo.
echo  ► PHP Version:
"%PHP_PATH%" -v | findstr /C:"PHP"
echo.
echo  ► Server Port: 8000
echo  ► Server URL:  http://localhost:8000
echo  ► Directory:   %CD%
echo.

echo ╔══════════════════════════════════════════════════════════╗
echo ║                    STARTING SERVER...                    ║
echo ╚══════════════════════════════════════════════════════════╝
echo.
echo  ✓ Press Ctrl+C to stop the server
echo  ✓ Open http://localhost:8000 in your browser
echo  ✓ Main script: http://localhost:8000/autosh.php
echo  ✓ Fetch proxies: http://localhost:8000/fetch_proxies.php
echo.
echo ════════════════════════════════════════════════════════════
echo.

REM Start PHP built-in server
"%PHP_PATH%" -S localhost:8000 -t "%CD%" router.php

pause
