"""
job_evaluator_10.py – Evaluates scraped job listings using the OpenAI ChatCompletion API.

Reads jobs from jobs.json and writes evaluated results to vurderede_jobs.json.

API key is read from the OPENAI_API_KEY environment variable.
Optionally, place your key in a .env file and install python-dotenv.
Do NOT hard-code or commit your API key.
"""

import os
import json

# Optional: load .env file if python-dotenv is installed
try:
    from dotenv import load_dotenv
    if os.path.exists(".env"):
        load_dotenv()
except ImportError:
    pass

try:
    import openai
except ImportError:
    print("ERROR: The 'openai' package is not installed.")
    print("Run: pip install openai")
    raise SystemExit(1)

from model_decider import decide_model

# ── Configuration ────────────────────────────────────────────────────────────

JOBS_FILE = "jobs.json"
OUTPUT_FILE = "vurderede_jobs.json"
RESUME_FILE = os.path.join("templates", "resume_template.txt")
PROMPT_FILE = os.path.join("templates", "prompt_template.txt")
BATCH_SIZE = 10
MAX_DESCRIPTION_LENGTH = 500

# ── API key ───────────────────────────────────────────────────────────────────

api_key = os.environ.get("OPENAI_API_KEY", "")
if not api_key:
    print("ERROR: OPENAI_API_KEY environment variable is not set.")
    print("Set it in your shell or in a .env file (see .env.example).")
    raise SystemExit(1)

openai.api_key = api_key

# ── Load templates ────────────────────────────────────────────────────────────

def load_file(path):
    with open(path, "r", encoding="utf-8") as f:
        return f.read()

cv_text = load_file(RESUME_FILE)
prompt_template = load_file(PROMPT_FILE)

# ── Load jobs ─────────────────────────────────────────────────────────────────

with open(JOBS_FILE, "r", encoding="utf-8") as f:
    all_jobs = json.load(f)

# Load any previously evaluated results so we can skip already-processed jobs
if os.path.exists(OUTPUT_FILE):
    with open(OUTPUT_FILE, "r", encoding="utf-8") as f:
        evaluated = json.load(f)
else:
    evaluated = []

evaluated_ids = {job.get("id") for job in evaluated}
pending_jobs = [j for j in all_jobs if j.get("id") not in evaluated_ids]

print(f"Total jobs: {len(all_jobs)} | Already evaluated: {len(evaluated_ids)} | Pending: {len(pending_jobs)}")

# ── Batch evaluation ──────────────────────────────────────────────────────────

def build_job_prompt(jobs):
    lines = []
    for job in jobs:
        lines.append(
            f"ID: {job.get('id')}\n"
            f"Title: {job.get('title', '')}\n"
            f"Company: {job.get('company', '')}\n"
            f"Description: {job.get('description', '')[:MAX_DESCRIPTION_LENGTH]}\n"
        )
    return "\n---\n".join(lines)


def evaluate_batch(jobs):
    job_list_prompt = build_job_prompt(jobs)
    full_prompt = prompt_template.format(cv=cv_text, job_list_prompt=job_list_prompt)

    model = decide_model(full_prompt)

    response = openai.ChatCompletion.create(
        model=model,
        messages=[{"role": "user", "content": full_prompt}],
        temperature=0.2,
    )

    raw = response.choices[0].message.content.strip()

    # Extract JSON array from response
    start = raw.find("[")
    end = raw.rfind("]") + 1
    if start == -1 or end == 0:
        print("WARNING: Could not find JSON array in response. Skipping batch.")
        return []

    return json.loads(raw[start:end])


# Process in batches
for i in range(0, len(pending_jobs), BATCH_SIZE):
    batch = pending_jobs[i : i + BATCH_SIZE]
    print(f"Evaluating jobs {i + 1}–{min(i + BATCH_SIZE, len(pending_jobs))}...")

    try:
        results = evaluate_batch(batch)
    except Exception as e:
        print(f"ERROR during batch {i // BATCH_SIZE + 1}: {e}")
        continue

    # Merge scores back into job records
    score_map = {r["id"]: r for r in results}
    for job in batch:
        job_id = job.get("id")
        if job_id in score_map:
            job["score"] = score_map[job_id].get("score", 0)
            job["reason"] = score_map[job_id].get("reason", "")
        else:
            job["score"] = 0
            job["reason"] = "Not rated in this batch."
        evaluated.append(job)

    # Save after every batch so progress is not lost on error
    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        json.dump(evaluated, f, ensure_ascii=False, indent=2)

print(f"Done. Results saved to {OUTPUT_FILE}")
