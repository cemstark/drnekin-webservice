@echo off
setlocal
cd /d "%~dp0"

echo [1/3] Sanal ortam (venv) hazirlaniyor...
if not exist ".venv\Scripts\python.exe" (
  python -m venv .venv
  if errorlevel 1 (
    echo Venv olusturulamadi. Python kurulu mu?
    pause
    exit /b 1
  )
)

echo [2/3] Paketler yukleniyor (requirements.txt)...
".venv\Scripts\python.exe" -m pip install --upgrade pip
".venv\Scripts\python.exe" -m pip install -r requirements.txt
if errorlevel 1 (
  echo Paket yukleme basarisiz.
  pause
  exit /b 1
)

echo [3/3] Tamam. Artik QR-URET.bat (veya QR-URET.vbs) ile tek tikla QR uretebilirsin.
pause
exit /b 0

