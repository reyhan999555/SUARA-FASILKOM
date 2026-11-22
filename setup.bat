@echo off
setlocal
title Suara Fasilkom
cd /d "%~dp0"

set "XAMPP=C:\xampp"
set "PHP=%XAMPP%\php\php.exe"
set "PORT=8000"

if not exist "%PHP%" (
  echo PHP XAMPP tidak ditemukan di %XAMPP%\php.
  echo Pasang XAMPP di C:\xampp, atau ubah baris "set XAMPP=" di file ini.
  pause
  exit /b 1
)

echo [1/3] Menyalakan MySQL XAMPP...
tasklist /FI "IMAGENAME eq mysqld.exe" 2>nul | find /I "mysqld.exe" >nul
if errorlevel 1 (
  if exist "%XAMPP%\mysql_start.bat" (
    start "MySQL XAMPP" /min cmd /c "%XAMPP%\mysql_start.bat"
  ) else (
    echo Nyalakan MySQL lewat XAMPP Control Panel, lalu tekan sembarang tombol.
    pause
  )
) else (
  echo      MySQL sudah menyala.
)

echo [2/3] Menyiapkan database suara_fasilkom...
"%PHP%" app.php setup
if errorlevel 1 (
  echo Setup database gagal. Pastikan MySQL menyala di XAMPP Control Panel.
  pause
  exit /b 1
)

echo [3/3] Menjalankan web di http://localhost:%PORT%
echo      Biarkan jendela ini terbuka selama web dipakai. Tutup jendela untuk mematikan web.
start "" "http://localhost:%PORT%/"
"%PHP%" -S localhost:%PORT%
