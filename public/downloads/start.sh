#!/bin/bash
cd "$(dirname "$0")"
echo
echo " ================================"
echo "   JobHunter  Lokal Scraper"
echo " ================================"
echo

if ! command -v python3 &>/dev/null; then
    echo "FEJL: Python 3 er ikke installeret!"
    echo "Installer Python 3 fra  https://python.org  og genstart dette script."
    exit 1
fi

echo "Opdaterer afhaengigheder ..."
python3 -m pip install -r requirements.txt -q
echo "Starter scraper paa  http://localhost:7474 ..."
echo "Tryk Ctrl+C for at stoppe."
echo
python3 helper.py
