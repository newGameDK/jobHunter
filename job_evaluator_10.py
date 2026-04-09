# job_evaluator_10.py
#
# Evaluates job listings in jobs.json against your CV using the OpenAI API.
#
# API KEY SECURITY:
#   - Set OPENAI_API_KEY in your .env file (copy .env.example to .env).
#   - Never hard-code or commit your API key.
#   - Never store the key in encryption_data.txt or any other file that may be committed.

import os
import json
import time

# Load .env file if python-dotenv is available (optional but recommended)
try:
    from dotenv import load_dotenv
    load_dotenv()
except ImportError:
    pass  # python-dotenv not installed; rely on environment variable

from openai import OpenAI, OpenAIError

# ── API key ──────────────────────────────────────────────────────────────────
api_key = os.environ.get("OPENAI_API_KEY")
if not api_key:
    print(
        "ERROR: OPENAI_API_KEY is not set.\n"
        "  1. Copy .env.example to .env\n"
        "  2. Set OPENAI_API_KEY=sk-your-real-key-here in .env\n"
        "  — or — export OPENAI_API_KEY=sk-your-real-key-here in your shell."
    )
    raise SystemExit(1)

# Do NOT print or log the API key.
client = OpenAI(api_key=api_key)

# ── Configuration ─────────────────────────────────────────────────────────────
JSON_FILE = "jobs.json"
RESUME_FILE = "resume.txt"
PROMPT_TEMPLATE_FILE = "templates/prompt_template.txt"
BATCH_SIZE = 10          # number of jobs per API call
SLEEP_BETWEEN_BATCHES = 1  # seconds; adjust to stay within rate limits

# ── Load model decider ────────────────────────────────────────────────────────
try:
    from model_decider import decide_model
except ImportError:
    def decide_model(text):
        """Fallback: always use gpt-3.5-turbo."""
        return "gpt-3.5-turbo"


def load_json(path):
    if not os.path.exists(path):
        return []
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


def save_json(path, data):
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)


def load_text(path):
    if not os.path.exists(path):
        return ""
    with open(path, "r", encoding="utf-8") as f:
        return f.read()


def build_job_list_prompt(jobs):
    """Format a list of job dicts into a numbered prompt string."""
    lines = []
    for job in jobs:
        job_id = job.get("id", "?")
        title = job.get("title", "")
        company = job.get("company", "")
        description = job.get("description", "")
        lines.append(f"Job ID: {job_id}\nTitel: {title}\nVirksomhed: {company}\nBeskrivelse: {description}\n")
    return "\n---\n".join(lines)


def evaluate_batch(cv_text, jobs, prompt_template):
    """Send one batch of jobs to OpenAI and return a dict of {job_id: score}."""
    job_list_prompt = build_job_list_prompt(jobs)
    prompt = prompt_template.replace("{cv}", cv_text).replace("{job_list_prompt}", job_list_prompt)

    model = decide_model(prompt)

    response = client.chat.completions.create(
        model=model,
        messages=[{"role": "user", "content": prompt}],
        temperature=0.2,
    )

    raw = response.choices[0].message.content.strip()

    try:
        scores = json.loads(raw)
    except json.JSONDecodeError:
        print(f"  Warning: could not parse JSON response: {raw[:200]}")
        scores = {}

    return scores


def main():
    jobs = load_json(JSON_FILE)
    if not jobs:
        print(f"No jobs found in {JSON_FILE}. Run jobhunter_10.py first.")
        return

    cv_text = load_text(RESUME_FILE)
    if not cv_text:
        print(
            f"WARNING: {RESUME_FILE} not found or empty.\n"
            "  Copy templates/resume_template.txt to resume.txt and fill in your CV."
        )

    prompt_template = load_text(PROMPT_TEMPLATE_FILE)
    if not prompt_template:
        print(f"WARNING: {PROMPT_TEMPLATE_FILE} not found. Using a minimal default prompt.")
        prompt_template = (
            "Rate each job 0-10 for fit with this CV.\n"
            "Respond ONLY with JSON: {{\"job_id\": score}}\n\n"
            "CV:\n{cv}\n\nJobs:\n{job_list_prompt}"
        )

    # Only evaluate jobs that don't already have a score
    to_evaluate = [j for j in jobs if j.get("score") is None]
    print(f"Jobs to evaluate: {len(to_evaluate)} (of {len(jobs)} total)")

    for i in range(0, len(to_evaluate), BATCH_SIZE):
        batch = to_evaluate[i : i + BATCH_SIZE]
        print(f"  Evaluating batch {i // BATCH_SIZE + 1} ({len(batch)} jobs)...")

        try:
            scores = evaluate_batch(cv_text, batch, prompt_template)
        except OpenAIError as exc:
            print(f"  OpenAI API error: {exc}")
            break

        # Write scores back to the job objects in the main list
        score_map = {}
        for job_id_raw, score in scores.items():
            try:
                score_map[int(job_id_raw)] = score
            except (ValueError, TypeError):
                score_map[job_id_raw] = score

        for job in jobs:
            jid = job.get("id")
            if jid in score_map:
                job["score"] = score_map[jid]

        save_json(JSON_FILE, jobs)
        time.sleep(SLEEP_BETWEEN_BATCHES)

    print("Evaluation complete.")


if __name__ == "__main__":
    main()
