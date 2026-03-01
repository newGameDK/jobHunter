# jobHunter

A Python-based job hunting tool that scrapes job listings from [Jobindex.dk](https://www.jobindex.dk/), evaluates them against your CV using the OpenAI API, and helps you keep track of what to apply for.

---

## Features

- **Scraper** (`jobhunter_10.py`) — fetches job listings from Jobindex and stores them in `jobs.json`
- **Evaluator** (`job_evaluator_10.py`) — scores each job 0–10 using GPT, based on your CV and a prompt template
- **Cleaner** (`job_cleaner.py`) — moves low-scoring or dismissed jobs out of `jobs.json`
- **Model decider** (`model_decider.py`) — picks the right GPT model based on token count
- **Launcher** (`launcher.py`) — simple GUI to run all tools from one window

---

## Quick Start

```bash
# 1. Clone the repo and enter the project directory
git clone https://github.com/newGameDK/jobHunter.git
cd jobHunter

# 2. Create and activate a virtual environment (recommended)
python -m venv .venv
source .venv/bin/activate   # Windows: .venv\Scripts\activate

# 3. Install dependencies
pip install -r requirements.txt

# 4. Configure your API key
cp .env.example .env
#    Open .env and set your real key:  OPENAI_API_KEY=sk-...

# 5. Add your CV
#    Copy templates/resume_template.txt to resume.txt and replace the placeholder text.
#    TODO: replace templates/resume_template.txt content with your own CV before running.

# 6. Run the scraper
python jobhunter_10.py

# 7. Evaluate jobs
python job_evaluator_10.py

# 8. Clean up low-scoring jobs
python job_cleaner.py

# — or use the launcher GUI —
python launcher.py
```

---

## Security Notes

> **NEVER** commit secrets to this repository.

- `.env` is listed in `.gitignore` — keep your `OPENAI_API_KEY` there and nowhere else.
- `encryption_data.txt` must **never** be committed. It is already in `.gitignore`.
- If you accidentally committed either file, rotate your API key immediately and purge the file from git history.
- The template files in `templates/` are safe to commit — they contain **no** real keys or personal data.

---

## Job Data Files

The following files are generated locally and are **gitignored** — they stay on your machine only:

| File | Description |
|---|---|
| `jobs.json` | Raw scraped job listings |
| `vurderede_jobs.json` | Jobs that have been evaluated |
| `slettede_jobs.json` | Jobs that have been dismissed / cleaned |

---

## Links

- Jobindex: <https://www.jobindex.dk/>
- OpenAI API: <https://platform.openai.com/>

---

## Contact

See repository issues for questions and bug reports.
