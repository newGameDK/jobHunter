# jobHunter

A Python toolkit for scraping job listings, evaluating them with OpenAI, and managing results.

## Quick Start

```bash
# 1. Create and activate a virtual environment
python3 -m venv .venv
source .venv/bin/activate   # Windows: .venv\Scripts\activate

# 2. Install dependencies
pip install -r requirements.txt

# 3. Set your OpenAI API key
cp .env.example .env        # then edit .env and fill in your key
# OR export it directly:
export OPENAI_API_KEY=your-openai-key-here

# 4. Run the scraper (opens a GUI to enter a search URL)
python jobhunter_10.py

# 5. Evaluate scraped jobs against your CV
python job_evaluator_10.py

# 6. Remove low-scoring jobs from the list
python job_cleaner.py

# 7. (Optional) Launch the GUI launcher
python launcher.py
```

## Scripts

| Script | Description |
|---|---|
| `jobhunter_10.py` | Scrapes job listings from Jobindex and saves them to `jobs.json` |
| `job_evaluator_10.py` | Rates jobs using OpenAI ChatCompletion and writes results to `vurderede_jobs.json` |
| `job_cleaner.py` | Removes jobs scored below a threshold (default: 40) from `vurderede_jobs.json` |
| `launcher.py` | Tkinter GUI launcher for all scripts |
| `model_decider.py` | Helper that selects the appropriate OpenAI model based on token count |

## Templates

- `templates/resume_template.txt` — Your CV/resume text (replace with your own content)
- `templates/prompt_template.txt` — Prompt sent to OpenAI; uses `{cv}` and `{job_list_prompt}` placeholders

## Security

> ⚠️ **Do NOT commit secrets.**
>
> - Copy `.env.example` to `.env` and add your `OPENAI_API_KEY` there.
> - `.env` and all job data files (`jobs.json`, `vurderede_jobs.json`, etc.) are listed in `.gitignore` and will not be committed.
> - Never hard-code API keys in source files.
