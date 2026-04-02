@echo off
title JobHunter - Lokal Scraper
cd /d "%~dp0"
echo.
echo  ================================
echo    JobHunter  Lokal Scraper
echo  ================================
echo.

python --version >nul 2>&1
if %errorlevel% neq 0 (
    echo FEJL: Python er ikke installeret!
    echo Hent Python 3 fra  https://www.python.org
    echo Sorg for at markere "Add Python to PATH" under installationen.
    pause
    exit /b 1
)

echo Opdaterer afhaengigheder ...
pip install -r requirements.txt -q
echo Starter scraper paa  http://localhost:7474 ...
echo Tryk Ctrl+C for at stoppe.
echo.
python helper.py
pause
