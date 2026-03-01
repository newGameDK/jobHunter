# job_cleaner.py
#
# Moves jobs with a score below DEFAULT_MIN_SCORE from jobs.json
# to slettede_jobs.json (dismissed jobs).
#
# Usage: python job_cleaner.py

import json
import os

JSON_FILE = "jobs.json"
DELETED_FILE = "slettede_jobs.json"
DEFAULT_MIN_SCORE = 6  # jobs scoring below this are moved to DELETED_FILE


def load_json(path):
    if not os.path.exists(path):
        return []
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


def save_json(path, data):
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)


def main():
    jobs = load_json(JSON_FILE)
    deleted = load_json(DELETED_FILE)

    keep = []
    to_delete = []

    for job in jobs:
        score = job.get("score")
        if score is not None and score < DEFAULT_MIN_SCORE:
            to_delete.append(job)
        else:
            keep.append(job)

    if not to_delete:
        print("No jobs below the minimum score threshold. Nothing to clean.")
        return

    deleted.extend(to_delete)
    save_json(JSON_FILE, keep)
    save_json(DELETED_FILE, deleted)

    print(
        f"Moved {len(to_delete)} job(s) with score < {DEFAULT_MIN_SCORE} "
        f"to {DELETED_FILE}. {len(keep)} job(s) remain in {JSON_FILE}."
    )


if __name__ == "__main__":
    main()
