@echo off
REM Auto Proxy Health Check - Windows Task Scheduler Script
REM Checks proxy health every hour and removes dead ones

cd /d "d:\Drive  E\ALL GAME\SHOPIFY API\imp"

echo [%date% %time%] Starting proxy health check...
C:\xampp\php\php.exe proxy_cache_refresh.php

echo [%date% %time%] Proxy health check completed
