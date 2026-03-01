"""
job_cleaner.py – Removes low-scoring job entries from vurderede_jobs.json.

Usage:
    python job_cleaner.py [--min-score N] [--file PATH]

Options:
    --min-score N   Remove jobs with score below N (default: 40)
    --file PATH     Path to the evaluated jobs JSON file (default: vurderede_jobs.json)
"""

import json
import os
import argparse

DEFAULT_MIN_SCORE = 40
DEFAULT_FILE = "vurderede_jobs.json"
DELETED_FILE = "slettede_jobs.json"


def load_jobs(path):
    if not os.path.exists(path):
        print(f"File not found: {path}")
        raise SystemExit(1)
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


def save_jobs(path, jobs):
    with open(path, "w", encoding="utf-8") as f:
        json.dump(jobs, f, ensure_ascii=False, indent=2)


def clean_jobs(jobs, min_score):
    kept = [j for j in jobs if j.get("score", 0) >= min_score]
    removed = [j for j in jobs if j.get("score", 0) < min_score]
    return kept, removed


def main():
    parser = argparse.ArgumentParser(description="Remove low-scoring jobs from the evaluated jobs file.")
    parser.add_argument("--min-score", type=int, default=DEFAULT_MIN_SCORE,
                        help=f"Minimum score to keep (default: {DEFAULT_MIN_SCORE})")
    parser.add_argument("--file", default=DEFAULT_FILE,
                        help=f"Path to evaluated jobs JSON file (default: {DEFAULT_FILE})")
    args = parser.parse_args()

    jobs = load_jobs(args.file)
    kept, removed = clean_jobs(jobs, args.min_score)

    print(f"Total jobs: {len(jobs)}")
    print(f"Keeping {len(kept)} jobs with score >= {args.min_score}")
    print(f"Removing {len(removed)} jobs with score < {args.min_score}")

    if removed:
        # Append removed jobs to the deleted log
        existing_deleted = []
        if os.path.exists(DELETED_FILE):
            with open(DELETED_FILE, "r", encoding="utf-8") as f:
                existing_deleted = json.load(f)
        save_jobs(DELETED_FILE, existing_deleted + removed)
        print(f"Removed jobs saved to {DELETED_FILE}")

    save_jobs(args.file, kept)
    print(f"Updated {args.file}")


if __name__ == "__main__":
    main()
