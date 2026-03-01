#!/usr/bin/env python3
"""
launcher.py — Simple GUI to run jobHunter tools locally.

Provides buttons to launch:
- jobhunter_10.py  (scraper)
- job_evaluator_10.py  (evaluator)
- job_cleaner.py  (cleaner)
"""
import subprocess
import sys
import tkinter as tk
from tkinter import messagebox


def run_script(script: str, args: list = None):
    """Run a Python script as a subprocess."""
    cmd = [sys.executable, script] + (args or [])
    try:
        subprocess.Popen(cmd)
    except FileNotFoundError:
        messagebox.showerror("Error", f"Script not found: {script}")
    except Exception as e:
        messagebox.showerror("Error", str(e))


def build_ui(root: tk.Tk):
    root.title("jobHunter Launcher")
    root.resizable(False, False)

    tk.Label(root, text="jobHunter Tools", font=("Helvetica", 14, "bold"),
             pady=10).pack()

    tk.Button(root, text="Run Scraper (jobhunter_10.py)", width=40,
              command=lambda: run_script("jobhunter_10.py")).pack(pady=4)

    tk.Button(root, text="Run Evaluator (job_evaluator_10.py)", width=40,
              command=lambda: run_script("job_evaluator_10.py")).pack(pady=4)

    tk.Button(root, text="Run Cleaner (job_cleaner.py)", width=40,
              command=lambda: run_script("job_cleaner.py",
                                         ["vurderede_jobs.json", "--min-score", "40"])).pack(pady=4)

    tk.Button(root, text="Quit", width=40, command=root.destroy).pack(pady=8)


def main():
    root = tk.Tk()
    build_ui(root)
    root.mainloop()


if __name__ == "__main__":
    main()
