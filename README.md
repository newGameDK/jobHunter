# jobHunter

Automated job-hunting tools for Jobindex (scraper, AI evaluator, expiry cleaner) — templated and safe to share.

Quick overview
- jobhunter_10.py — Scrapes Jobindex search pages into a local jobs.json file.
- job_evaluator_10.py — Uses OpenAI to evaluate jobs against your resume (reads API key from environment).
- job_cleaner.py — Checks evaluated jobs for expiry and writes expired ones to slettede_jobs.json.
- launcher.py — Simple GUI to run the tools locally.
- model_decider.py — Optional cost estimator (uses tiktoken).

Security & Privacy
- Do NOT commit secrets. This repo uses `.env.example`; create a `.env` file locally or set environment variable OPENAI_API_KEY.
- encryption_data.txt MUST NOT be committed. It is intentionally excluded by .gitignore.
- All job data (jobs.json, vurderede_jobs.json, slettede_jobs.json, logs, debug HTML) are ignored and remain local.

Quick start
1. Clone:
   git clone https://github.com/newGameDK/jobHunter.git
   cd jobHunter

2. Create virtual env and install:
   python -m venv .venv
   source .venv/bin/activate  # or .venv\Scripts\activate on Windows
   pip install -r requirements.txt

3. Configure API key:
   - Copy `.env.example` to `.env` and set `OPENAI_API_KEY=your-key-here`
   - Or export OPENAI_API_KEY in your shell:
     export OPENAI_API_KEY="sk-..."

4. Fill templates:
   - Edit `templates/resume_template.txt` -> save as `resume.txt` in repo root
   - Edit `templates/prompt_template.txt` -> keep or customize

5. Run:
   - Scraper GUI: python jobhunter_10.py
   - Evaluate (CLI): python job_evaluator_10.py
   - Cleaner: python job_cleaner.py --input vurderede_jobs.json --min-score 40

Notes
- Python 3.10+ recommended.
- Optional dependencies: `tiktoken` (for model_decider), `python-dotenv` (to auto-load .env).
- See SETUP.md for full instructions and troubleshooting.
