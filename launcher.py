"""
launcher.py – Tkinter GUI launcher for jobHunter scripts.
"""

import subprocess
import sys
import tkinter as tk
from tkinter import messagebox


def run_script(script_name):
    try:
        subprocess.Popen([sys.executable, script_name])
    except Exception as e:
        messagebox.showerror("Error", f"Could not launch {script_name}:\n{e}")


def main():
    root = tk.Tk()
    root.title("jobHunter Launcher")
    root.resizable(False, False)

    tk.Label(root, text="jobHunter", font=("Helvetica", 18, "bold")).pack(pady=(20, 4))
    tk.Label(root, text="Select a script to run", font=("Helvetica", 11)).pack(pady=(0, 16))

    buttons = [
        ("🔍  Scrape Jobs", "jobhunter_10.py"),
        ("🤖  Evaluate Jobs", "job_evaluator_10.py"),
        ("🧹  Clean Low-Score Jobs", "job_cleaner.py"),
    ]

    for label, script in buttons:
        tk.Button(
            root,
            text=label,
            width=28,
            command=lambda s=script: run_script(s),
            font=("Helvetica", 11),
            pady=6,
        ).pack(padx=30, pady=6)

    tk.Button(
        root,
        text="Quit",
        width=28,
        command=root.destroy,
        font=("Helvetica", 11),
        pady=6,
        fg="red",
    ).pack(padx=30, pady=(6, 20))

    root.mainloop()


if __name__ == "__main__":
    main()
