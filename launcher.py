# launcher.py
#
# Simple Tkinter GUI launcher for jobHunter.
#
# Prerequisites:
#   - All scripts (jobhunter_10.py, job_evaluator_10.py, job_cleaner.py,
#     model_decider.py) must be in the same working directory as this file.
#   - OPENAI_API_KEY must be set in .env or as an environment variable.
#   - Job data files (jobs.json, etc.) are local and gitignored.
#
# Usage: python launcher.py

import subprocess
import sys
import threading
import tkinter as tk
from tkinter import scrolledtext


def run_script(script_name, output_widget):
    """Run a Python script in a subprocess and stream output to the widget."""
    output_widget.config(state=tk.NORMAL)
    output_widget.insert(tk.END, f"\n▶ Running {script_name}...\n", "header")
    output_widget.see(tk.END)

    try:
        proc = subprocess.Popen(
            [sys.executable, script_name],
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            encoding="utf-8",
            errors="replace",
        )
        for line in proc.stdout:
            output_widget.insert(tk.END, line)
            output_widget.see(tk.END)
        proc.wait()
        status = "✓ Done" if proc.returncode == 0 else f"✗ Exited with code {proc.returncode}"
        output_widget.insert(tk.END, f"{status}\n", "status")
    except FileNotFoundError:
        output_widget.insert(tk.END, f"ERROR: {script_name} not found in current directory.\n", "error")

    output_widget.see(tk.END)
    output_widget.config(state=tk.DISABLED)


def launch(script_name, output_widget):
    """Start script in a background thread so the GUI stays responsive."""
    thread = threading.Thread(target=run_script, args=(script_name, output_widget), daemon=True)
    thread.start()


def build_gui():
    root = tk.Tk()
    root.title("jobHunter Launcher")
    root.resizable(True, True)

    btn_frame = tk.Frame(root, padx=8, pady=8)
    btn_frame.pack(fill=tk.X)

    output = scrolledtext.ScrolledText(root, width=80, height=24, state=tk.DISABLED, wrap=tk.WORD)
    output.pack(fill=tk.BOTH, expand=True, padx=8, pady=(0, 8))
    output.tag_config("header", foreground="blue")
    output.tag_config("status", foreground="green")
    output.tag_config("error", foreground="red")

    scripts = [
        ("🔍 Scrape Jobs", "jobhunter_10.py"),
        ("🤖 Evaluate Jobs", "job_evaluator_10.py"),
        ("🧹 Clean Jobs", "job_cleaner.py"),
    ]

    for label, script in scripts:
        btn = tk.Button(
            btn_frame,
            text=label,
            width=18,
            command=lambda s=script: launch(s, output),
        )
        btn.pack(side=tk.LEFT, padx=4)

    root.mainloop()


if __name__ == "__main__":
    build_gui()
