#!/usr/bin/env python3
"""
Evaluator (sanitized):
- Reads jobs.json and resume.txt
- Reads prompt_template from templates/prompt_template.txt
- Uses OPENAI_API_KEY from environment (or .env if you load it)
- Writes vurderede_jobs.json
"""
import os
import json
import time
from datetime import datetime
try:
    import openai
except Exception:
    openai = None

def log_message(message):
    with open('evaluation_log.txt', 'a', encoding='utf-8') as log_file:
        log_file.write(message + '\n')


def load_text(path):
    with open(path, 'r', encoding='utf-8') as f:
        return f.read()


def main():
    if openai is None:
        print("Missing openai package. Install: pip install openai")
        return

    api_key = os.environ.get('OPENAI_API_KEY')
    if not api_key:
        print("OPENAI_API_KEY not set. See README and .env.example")
        return
    openai.api_key = api_key

    jobs_file = 'jobs.json'
    vurderede_jobs_file = 'vurderede_jobs.json'
    resume_file = 'resume.txt'
    prompt_template_file = 'templates/prompt_template.txt'

    # load resume
    if not os.path.exists(resume_file):
        print(f"Missing {resume_file}. Create it from templates/resume_template.txt")
        return
    cv_text = load_text(resume_file)

    # load prompt template
    if not os.path.exists(prompt_template_file):
        print(f"Missing {prompt_template_file}. Copy templates/prompt_template.txt")
        return
    prompt_template = load_text(prompt_template_file)

    # load jobs
    try:
        with open(jobs_file, 'r', encoding='utf-8') as f:
            jobs = json.load(f)
    except FileNotFoundError:
        print("No jobs.json found. Run the scraper first.")
        return

    vurderede_jobs = {}
    if os.path.exists(vurderede_jobs_file):
        try:
            with open(vurderede_jobs_file, 'r', encoding='utf-8') as f:
                vurderede_jobs = json.load(f)
        except Exception:
            vurderede_jobs = {}

    vurderede_links = {v.get("link") for v in vurderede_jobs.values() if isinstance(v, dict) and "link" in v}

    jobs_to_eval = [j for j in jobs if j.get('direct_link') not in vurderede_links]

    def call_model(prompt: str):
        try:
            resp = openai.ChatCompletion.create(
                model="gpt-4o",
                messages=[{"role": "user", "content": prompt}],
                temperature=0
            )
            return resp.choices[0].message.content
        except Exception as e:
            log_message(f"API call failed: {e}")
            return None

    batch_size = 15
    for i in range(0, len(jobs_to_eval), batch_size):
        batch = jobs_to_eval[i:i+batch_size]
        clean_jobs = []
        lookup = {}
        for job in batch:
            cj = {
                "title": job.get("title", ""),
                "description": job.get("description", ""),
                "rejsetid": job.get("rejsetid", ""),
                "direct_link": job.get("direct_link", "")
            }
            clean_jobs.append(cj)
            lookup[cj["direct_link"]] = job

        job_list_prompt = "\n".join([json.dumps(j, ensure_ascii=False) for j in clean_jobs])
        prompt = prompt_template.replace("{cv}", cv_text).replace("{job_list_prompt}", job_list_prompt)

        print("Sending batch to model...")
        model_resp = call_model(prompt)
        if not model_resp:
            print("Model call failed for this batch.")
            time.sleep(1)
            continue

        svar_json = model_resp.strip()
        if svar_json.startswith("```json"):
            svar_json = svar_json.strip("```json ").strip("```")
        try:
            vurderinger = json.loads(svar_json)
        except Exception as e:
            log_message(f"Failed to parse model JSON: {e}")
            continue

        for vurdering in vurderinger:
            link = vurdering.get("link", "")
            if link in lookup:
                vurdering["date_added"] = lookup[link].get("date_added", "")
                vurderede_jobs[link] = vurdering
                log_message(json.dumps(vurdering, ensure_ascii=False, indent=2))

        with open(vurderede_jobs_file, 'w', encoding='utf-8') as f:
            json.dump(vurderede_jobs, f, ensure_ascii=False, indent=2)

        time.sleep(1)

    print("Done. Evaluations saved to", vurderede_jobs_file)

if __name__ == "__main__":
    main()
