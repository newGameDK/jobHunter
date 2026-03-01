# jobHunter – Setup Guide

## Requirements

- Python 3.10 or newer (required)
- pip

---

## 1. Clone the repository

```bash
git clone https://github.com/newGameDK/jobHunter.git
cd jobHunter
```

---

## 2. Create a virtual environment

```bash
python3 -m venv .venv
source .venv/bin/activate        # Windows: .venv\Scripts\activate
```

---

## 3. Install dependencies

```bash
pip install -r requirements.txt
```

Optional packages (recommended):

```bash
pip install python-dotenv   # automatically loads .env file
pip install tiktoken        # accurate token counting for model selection
```

---

## 4. Configure your OpenAI API key

**Option A – `.env` file (recommended)**

```bash
cp .env.example .env
```

Open `.env` and replace the placeholder:

```
OPENAI_API_KEY=your-openai-key-here
```

**Option B – environment variable**

```bash
export OPENAI_API_KEY=your-openai-key-here   # Linux/macOS
set OPENAI_API_KEY=your-openai-key-here      # Windows CMD
```

> ⚠️ `.env` is listed in `.gitignore`. Never commit it to version control.

---

## 5. Edit the templates

### `templates/resume_template.txt`

Replace the placeholder content with your own CV/resume text.

### `templates/prompt_template.txt`

Adjust the rating criteria if needed. The file uses two placeholders:

- `{cv}` — will be replaced with the contents of `resume_template.txt`
- `{job_list_prompt}` — will be replaced with the formatted list of jobs

---

## 6. Run the scripts

### Scraper

```bash
python jobhunter_10.py
```

A dialog box will ask for a Jobindex search URL. Results are saved to `jobs.json`.

### Evaluator

```bash
python job_evaluator_10.py
```

Reads `jobs.json`, evaluates each listing with OpenAI, and writes scores to `vurderede_jobs.json`.

### Cleaner

```bash
python job_cleaner.py
```

Removes entries from `vurderede_jobs.json` that score below the minimum threshold (default: 40).

### GUI Launcher

```bash
python launcher.py
```

Opens a Tkinter window with buttons for each script.

---

## 7. Troubleshooting

| Problem | Solution |
|---|---|
| `ModuleNotFoundError: openai` | Run `pip install openai` |
| `OPENAI_API_KEY not set` | Check your `.env` file or environment variable |
| Scraper shows empty results | Try a different Jobindex search URL |
| `ModuleNotFoundError: tkinter` | Install `python3-tk` via your OS package manager |
| Token limit errors | Install `tiktoken` so the model decider can count tokens accurately |

---

## Notes

- Job data files (`jobs.json`, `vurderede_jobs.json`, `slettede_jobs.json`) are gitignored — they contain personal search results.
- Debug HTML pages (`debug_page*.html`) are also gitignored.
- Python 3.10+ is required for best compatibility.
