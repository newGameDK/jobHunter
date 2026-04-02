#!/usr/bin/env python3
"""
JobHunter – Lokal Scraper Installer
=====================================
Kør dette script for at installere den lokale scraper på din PC.

Kræver Python 3.8+  (standard bibliotek – ingen pip-pakker nødvendige for installeren).
"""

import os
import sys
import shutil
import platform
import subprocess
import tkinter as tk
from tkinter import filedialog, messagebox, scrolledtext

SCRIPT_DIR    = os.path.dirname(os.path.abspath(__file__))
FILES_TO_COPY = ['helper.py', 'requirements.txt']
IS_WINDOWS    = platform.system() == 'Windows'


class InstallerApp:
    def __init__(self, root: tk.Tk) -> None:
        self.root = root
        self.root.title('JobHunter – Installer')
        self.root.resizable(False, False)
        self._build_ui()
        self._center_window()

    # ── UI ────────────────────────────────────────────────────────────────

    def _build_ui(self) -> None:
        # Header bar
        hdr = tk.Frame(self.root, bg='#2563eb', pady=14)
        hdr.pack(fill='x')
        tk.Label(hdr, text='🔍  JobHunter – Lokal Scraper',
                 font=('Helvetica', 14, 'bold'), fg='white', bg='#2563eb').pack()
        tk.Label(hdr, text='Installer til jobindex.dk-søgning',
                 font=('Helvetica', 9), fg='#bfdbfe', bg='#2563eb').pack()

        # Body
        body = tk.Frame(self.root, bg='white', padx=24, pady=18)
        body.pack(fill='both', expand=True)

        # Step 1 – folder selection
        tk.Label(body, text='Trin 1 – Vælg installationsmappe',
                 font=('Helvetica', 10, 'bold'), bg='white').pack(anchor='w')
        tk.Label(body,
                 text='Alle scriptfiler kopieres til den valgte mappe.\n'
                      'Standard: mappen "JobHunter" i din hjemme-sti.',
                 font=('Helvetica', 9), fg='#64748b', bg='white',
                 justify='left').pack(anchor='w', pady=(3, 10))

        row = tk.Frame(body, bg='white')
        row.pack(fill='x', pady=(0, 14))
        default = os.path.join(os.path.expanduser('~'), 'JobHunter')
        self.folder_var = tk.StringVar(value=default)
        tk.Entry(row, textvariable=self.folder_var, width=44,
                 font=('Helvetica', 9)).pack(side='left', fill='x', expand=True, padx=(0, 8))
        tk.Button(row, text='Gennemse …', command=self._browse,
                  bg='#e2e8f0', relief='flat', padx=10).pack(side='left')

        # Step 2 – options
        tk.Label(body, text='Trin 2 – Indstillinger',
                 font=('Helvetica', 10, 'bold'), bg='white').pack(anchor='w', pady=(4, 4))
        self.deps_var = tk.BooleanVar(value=True)
        tk.Checkbutton(body,
                       text='Installer Python-afhængigheder automatisk (anbefalet)',
                       variable=self.deps_var, bg='white').pack(anchor='w', pady=(0, 12))

        # Install button
        self.install_btn = tk.Button(
            body, text='  Installer  ', command=self._run_install,
            bg='#2563eb', fg='white', font=('Helvetica', 11, 'bold'),
            relief='flat', padx=14, pady=8, cursor='hand2')
        self.install_btn.pack(pady=(0, 12))

        # Log area
        tk.Label(body, text='Log:', font=('Helvetica', 9, 'bold'), bg='white').pack(anchor='w')
        self.log = scrolledtext.ScrolledText(
            body, width=58, height=9, font=('Courier', 9),
            state='disabled', bg='#f8fafc')
        self.log.pack(fill='both', expand=True)

    def _center_window(self) -> None:
        self.root.update_idletasks()
        w = self.root.winfo_reqwidth()
        h = self.root.winfo_reqheight()
        x = (self.root.winfo_screenwidth()  - w) // 2
        y = (self.root.winfo_screenheight() - h) // 2
        self.root.geometry(f'{w}x{h}+{x}+{y}')

    # ── Helpers ───────────────────────────────────────────────────────────

    def _browse(self) -> None:
        d = filedialog.askdirectory(
            title='Vælg installationsmappe',
            initialdir=self.folder_var.get())
        if d:
            self.folder_var.set(d)

    def _log(self, msg: str) -> None:
        self.log.config(state='normal')
        self.log.insert('end', msg + '\n')
        self.log.see('end')
        self.log.config(state='disabled')
        self.root.update()

    # ── Installation ──────────────────────────────────────────────────────

    def _run_install(self) -> None:
        target = self.folder_var.get().strip()
        if not target:
            messagebox.showerror('Fejl', 'Vælg venligst en installationsmappe.')
            return

        self.install_btn.config(state='disabled', text='Installerer …')
        self._log(f'Installationsmappe: {target}')

        # Create target directory
        try:
            os.makedirs(target, exist_ok=True)
            self._log('✓  Mappe klar')
        except OSError as exc:
            self._log(f'✗  Kunne ikke oprette mappe: {exc}')
            self.install_btn.config(state='normal', text='  Installer  ')
            return

        # Copy files
        all_ok = True
        for fname in FILES_TO_COPY:
            src = os.path.join(SCRIPT_DIR, fname)
            dst = os.path.join(target, fname)
            if not os.path.isfile(src):
                self._log(f'⚠  {fname} ikke fundet i installerens mappe – springer over')
                continue
            try:
                shutil.copy2(src, dst)
                self._log(f'✓  Kopierede  {fname}')
            except OSError as exc:
                self._log(f'✗  Fejl ved kopiering af {fname}: {exc}')
                all_ok = False

        if not all_ok:
            self._log('⚠  Nogle filer kunne ikke kopieres. Tjek rettigheder og prøv igen.')
            self.install_btn.config(state='normal', text='  Installer  ')
            return

        # Create platform launcher
        if IS_WINDOWS:
            self._make_bat(target)
        else:
            self._make_sh(target)

        # Optionally install pip dependencies
        if self.deps_var.get():
            self._install_deps(target)

        launcher = 'start.bat' if IS_WINDOWS else 'start.sh'
        self._log('\n✅  Installation fuldført!')
        self._log(f'📁  Filer installeret i:  {target}')
        self._log(f'🚀  Start scraperen ved at dobbeltklikke på:  {launcher}')
        self._log('🌐  Åbn derefter JobHunter i din browser og gå til "Søg jobs"')

        messagebox.showinfo(
            'Installation fuldført! 🎉',
            f'Lokal scraper installeret i:\n{target}\n\n'
            f'1. Dobbeltklik på "{launcher}" for at starte scraperen.\n'
            '2. Åbn JobHunter i din browser.\n'
            '3. Gå til "Søg jobs" – scraperen vises som aktiv.',
        )
        self.install_btn.config(state='normal', text='  Installer igen  ')

    def _make_bat(self, target: str) -> None:
        content = (
            '@echo off\r\n'
            'title JobHunter - Lokal Scraper\r\n'
            'cd /d "%~dp0"\r\n'
            'echo.\r\n'
            'echo  ================================\r\n'
            'echo    JobHunter  Lokal Scraper\r\n'
            'echo  ================================\r\n'
            'echo.\r\n'
            'python --version >nul 2>&1\r\n'
            'if %errorlevel% neq 0 (\r\n'
            '    echo FEJL: Python er ikke installeret!\r\n'
            '    echo Hent Python 3 fra  https://www.python.org\r\n'
            '    pause\r\n'
            '    exit /b 1\r\n'
            ')\r\n'
            'echo Opdaterer afhaengigheder ...\r\n'
            'pip install -r requirements.txt -q\r\n'
            'echo Starter scraper paa  http://localhost:7474 ...\r\n'
            'echo Tryk Ctrl+C for at stoppe.\r\n'
            'echo.\r\n'
            'python helper.py\r\n'
            'pause\r\n'
        )
        path = os.path.join(target, 'start.bat')
        with open(path, 'w', encoding='utf-8') as fh:
            fh.write(content)
        self._log('✓  Oprettede  start.bat')

    def _make_sh(self, target: str) -> None:
        content = (
            '#!/bin/bash\n'
            'cd "$(dirname "$0")"\n'
            'echo\n'
            'echo " ================================"\n'
            'echo "   JobHunter  Lokal Scraper"\n'
            'echo " ================================"\n'
            'echo\n'
            'if ! command -v python3 &>/dev/null; then\n'
            '    echo "FEJL: Python 3 er ikke installeret!"\n'
            '    echo "Installer Python 3 og genstart dette script."\n'
            '    exit 1\n'
            'fi\n'
            'echo "Opdaterer afhaengigheder ..."\n'
            'python3 -m pip install -r requirements.txt -q\n'
            'echo "Starter scraper paa  http://localhost:7474 ..."\n'
            'echo "Tryk Ctrl+C for at stoppe."\n'
            'echo\n'
            'python3 helper.py\n'
        )
        path = os.path.join(target, 'start.sh')
        with open(path, 'w', encoding='utf-8') as fh:
            fh.write(content)
        os.chmod(path, 0o755)
        self._log('✓  Oprettede  start.sh')

    def _install_deps(self, target: str) -> None:
        req = os.path.join(target, 'requirements.txt')
        if not os.path.isfile(req):
            self._log('⚠  requirements.txt ikke fundet – springer dep-installation over')
            return
        self._log('Installerer Python-afhængigheder (et øjeblik) …')
        try:
            result = subprocess.run(
                [sys.executable, '-m', 'pip', 'install', '-r', req, '-q'],
                capture_output=True, text=True, timeout=180,
            )
            if result.returncode == 0:
                self._log('✓  Afhængigheder installeret')
            else:
                self._log(f'⚠  pip returnerede fejl:\n{result.stderr[:300]}')
        except subprocess.TimeoutExpired:
            self._log('⚠  pip timeout – installer manuelt med:  pip install -r requirements.txt')
        except Exception as exc:
            self._log(f'⚠  Kunne ikke køre pip: {exc}')


def main() -> None:
    root = tk.Tk()
    InstallerApp(root)
    root.mainloop()


if __name__ == '__main__':
    main()
