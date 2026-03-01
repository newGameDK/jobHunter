#!/usr/bin/env python3
"""
job_cleaner.py — Checks evaluated jobs for expiry.
Reads vurderede_jobs.json (or a specified input file), verifies each job URL is still live,
and moves expired entries to slettede_jobs.json.

Usage:
    python job_cleaner.py [input_file] [--min-score SCORE] [--output OUTPUT] [--dry-run]
"""
import argparse
import json
import os
import sys

try:
    import requests
except ImportError:
    requests = None


def is_expired(url: str) -> bool:
    """Return True if the job URL returns a non-200 status or redirect away from the detail page."""
    if requests is None:
        return False
    try:
        resp = requests.head(url, allow_redirects=True, timeout=10)
        return resp.status_code >= 400
    except Exception:
        return False


def main():
    parser = argparse.ArgumentParser(description="Check evaluated jobs for expiry.")
    parser.add_argument("input", nargs="?", default="vurderede_jobs.json",
                        help="Input JSON file (default: vurderede_jobs.json)")
    parser.add_argument("--min-score", type=int, default=0,
                        help="Only check jobs with samlet_vurdering >= this value")
    parser.add_argument("--output", default=None,
                        help="Output file for remaining jobs (default: overwrite input)")
    parser.add_argument("--dry-run", action="store_true",
                        help="Do not write any files, just report")
    args = parser.parse_args()

    input_file = args.input
    output_file = args.output or input_file
    deleted_file = "slettede_jobs.json"

    if not os.path.exists(input_file):
        print(f"Input file not found: {input_file}")
        sys.exit(1)

    with open(input_file, "r", encoding="utf-8") as f:
        jobs = json.load(f)

    existing_deleted = {}
    if os.path.exists(deleted_file):
        try:
            with open(deleted_file, "r", encoding="utf-8") as f:
                existing_deleted = json.load(f)
        except Exception:
            existing_deleted = {}

    remaining = {}
    newly_deleted = {}

    for link, job in jobs.items():
        score = 0
        if isinstance(job, dict):
            rating = job.get("rating", {})
            if isinstance(rating, dict):
                score = rating.get("samlet_vurdering", 0)
        if score < args.min_score:
            remaining[link] = job
            continue
        print(f"Checking: {link}")
        if is_expired(link):
            print(f"  -> Expired")
            newly_deleted[link] = job
        else:
            remaining[link] = job

    print(f"\nTotal: {len(jobs)}, Remaining: {len(remaining)}, Expired: {len(newly_deleted)}")

    if args.dry_run:
        print("Dry run — no files written.")
        return

    existing_deleted.update(newly_deleted)
    with open(deleted_file, "w", encoding="utf-8") as f:
        json.dump(existing_deleted, f, ensure_ascii=False, indent=2)

    with open(output_file, "w", encoding="utf-8") as f:
        json.dump(remaining, f, ensure_ascii=False, indent=2)

    print(f"Wrote {len(remaining)} remaining jobs to {output_file}")
    print(f"Wrote {len(existing_deleted)} deleted jobs to {deleted_file}")


if __name__ == "__main__":
    main()
