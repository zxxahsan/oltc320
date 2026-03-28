@echo off
title GEMBOK CRON SCHEDULER
echo ========================================================
echo CRON SCHEDULER GEMBOK V3 - JANGAN TUTUP JENDELA INI
echo ========================================================
echo Memulai scheduler. Tekan CTRL+C untuk menghentikan.
echo.

:loop
echo [%time%] Menjalankan tugas otomatis (Invoce, Isolir, Usage, WA)...
php "cron\scheduler.php"
echo.
echo Menunggu 60 detik...
timeout /t 60 /nobreak >nul
goto loop
