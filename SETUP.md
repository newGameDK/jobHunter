Setup and detailed usage

1) Create repo (if not already)
   git clone https://github.com/newGameDK/jobHunter.git
   cd jobHunter

2) Python & virtualenv
   python --version  # use 3.10+
   python -m venv .venv
   source .venv/bin/activate   # Linux/macOS
   .venv\Scripts\activate      # Windows PowerShell (or use cmd scripts)

3) Install dependencies
   pip install -r requirements.txt

Optional:
   pip install python-dotenv
   pip install tiktoken   # only for model_decider.py

4) Configure OpenAI API key
   - Option A (recommended): Create .env file:
       cp .env.example .env
       # edit .env and set OPENAI_API_KEY=sk-...
     Then optionally install python-dotenv and the evaluator will read env vars.
   - Option B (env var): export OPENAI_API_KEY="sk-..." (Linux/macOS)
                     setx OPENAI_API_KEY "sk-..." (Windows)

5) Prepare resume and prompt
   - Edit `templates/resume_template.txt`, then save a copy as `resume.txt` in the repository root.
   - Edit `templates/prompt_template.txt` if you want custom evaluation rules; the evaluator expects `{cv}` and `{job_list_prompt}` placeholders.

6) Running the tools
   - Scraper (GUI): python jobhunter_10.py
     Use menu -> Set Base URL to paste a Jobindex search URL (the first page). Click Run Scraper.
   - Evaluator (CLI): python job_evaluator_10.py
     - Must have resume.txt and prompt_template.txt (or customized copy)
     - Evaluator reads jobs.json and writes vurderede_jobs.json
   - Cleaner: python job_cleaner.py vurderede_jobs.json --min-score 40
     - By default it overwrites the input file unless --output specified. Use --dry-run to test.

7) Data & privacy
   - All job data stored in local files (jobs.json, vurderede_jobs.json, slettede_jobs.json). They are ignored by git.
   - NEVER commit `.env`, `encryption_data.txt`, or job data files.

8) Troubleshooting
   - ImportError: pip install -r requirements.txt
   - Missing API key: ensure OPENAI_API_KEY is set or .env exists and loaded
   - HTML parsing changes: jobindex might change layout; check debug_page*.html saved in the working directory.
