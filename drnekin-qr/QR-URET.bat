@echo off
setlocal
cd /d "%~dp0"

if exist ".venv\Scripts\python.exe" (
  ".venv\Scripts\python.exe" generate_and_sync.py
) else (
  echo Venv bulunamadi. Once KURULUM.bat calistirin.
  echo Sonra tekrar deneyin.
  pause
)

pause
exit /b 0

