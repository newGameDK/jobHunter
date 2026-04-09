# Setup Guide for jobHunter

This guide walks you through every step needed to get jobHunter running on your machine.

---

## 1. Requirements

- **Python 3.10+** (recommended; 3.8 minimum)
- A valid **OpenAI API key** — get one at <https://platform.openai.com/api-keys>
- Internet access to scrape Jobindex

---

## 2. Create a Virtual Environment

```bash
python -m venv .venv
source .venv/bin/activate      # macOS/Linux
# .venv\Scripts\activate       # Windows
```

---

## 3. Install Dependencies

```bash
pip install -r requirements.txt
```

This installs: `requests`, `beautifulsoup4`, `openai`, `tiktoken`.

---

## 4. Configure Your API Key

```bash
cp .env.example .env
```

Open `.env` in a text editor and replace the placeholder:

```
OPENAI_API_KEY=sk-your-real-key-here
```

> **Security reminder:** `.env` is gitignored. Never commit it.

**Alternative:** export the variable in your shell instead:

```bash
export OPENAI_API_KEY=sk-your-real-key-here
```

You can also use a tool like [direnv](https://direnv.net/) to load `.env` automatically.

---

## 5. Prepare Your CV / Resume

1. Copy the template to `resume.txt`:

   ```bash
   cp templates/resume_template.txt resume.txt
   ```

2. Open `resume.txt` and replace all placeholder text with your real CV content.

> **TODO:** `templates/resume_template.txt` contains example text. Replace it with your own information before running the evaluator.

`resume.txt` is read by `job_evaluator_10.py` at runtime. It is listed in `.gitignore` to protect your personal data — it will **not** be committed.

---

## 6. Running Each Script

### Scraper — `jobhunter_10.py`

Fetches job listings from Jobindex and stores them in `jobs.json`.

```bash
python jobhunter_10.py
```

The GUI will prompt you for a search URL. Paste a Jobindex search URL and press **Start**.

### Evaluator — `job_evaluator_10.py`

Reads `jobs.json` and `resume.txt`, sends batches to the OpenAI API, and writes scores back to `jobs.json`.

```bash
python job_evaluator_10.py
```

Requires `OPENAI_API_KEY` to be set (via `.env` or environment variable).

### Cleaner — `job_cleaner.py`

Moves jobs with a score below `DEFAULT_MIN_SCORE` (default: 6) from `jobs.json` to `slettede_jobs.json`.

```bash
python job_cleaner.py
```

### Model Decider — `model_decider.py`

Helper module used by the evaluator to choose the right GPT model based on token count. Not run directly.

### Launcher — `launcher.py`

Simple Tkinter GUI that lets you run all tools from one window.

```bash
python launcher.py
```

**GUI instructions:**
- All scripts must be in the same working directory as `launcher.py`.
- Click the buttons to start each tool.
- Output is shown in the scrollable text area.

---

## 7. Prompt Template

`templates/prompt_template.txt` contains the prompt sent to GPT along with your CV and job listings.

- `{cv}` is replaced with the contents of `resume.txt`.
- `{job_list_prompt}` is replaced with the current batch of job descriptions.

Customise the prompt template to change how jobs are evaluated.

---

## 8. Optional: encryption_data.txt

> **Strongly discouraged.** Storing your API key in a local encrypted file is no longer supported in this version. Use `.env` or an environment variable instead.

If you have an old `encryption_data.txt` file from a previous version:

1. **Remove it from your working directory.**
2. **Make sure it is not committed to git** — it is already listed in `.gitignore`.
3. Rotate your OpenAI API key if it was ever stored or accidentally committed.

---

## 9. Troubleshooting

| Error | Fix |
|---|---|
| `ModuleNotFoundError: No module named 'requests'` | Run `pip install -r requirements.txt` |
| `ModuleNotFoundError: No module named 'bs4'` | Run `pip install beautifulsoup4` |
| `ModuleNotFoundError: No module named 'openai'` | Run `pip install openai` |
| `ModuleNotFoundError: No module named 'tiktoken'` | Run `pip install tiktoken` |
| `OPENAI_API_KEY not set` | Copy `.env.example` to `.env` and add your key |
| Python version errors | Upgrade to Python 3.10+ |
| Tkinter not available | Install `python3-tk` (Linux: `sudo apt install python3-tk`) |

---

## 10. Gitignored Files

The following files are **local only** and will never be committed:

- `.env` — your API key
- `encryption_data.txt` — legacy secret file (do not use)
- `jobs.json`, `vurderede_jobs.json`, `slettede_jobs.json` — job data
- `evaluation_log.txt`, `last_url.txt` — runtime logs
- `debug_page*.html` — debug output
