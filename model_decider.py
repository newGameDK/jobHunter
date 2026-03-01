#!/usr/bin/env python3
"""
model_decider.py — Optional cost estimator for OpenAI API calls.

Estimates token usage and approximate cost for evaluating a batch of jobs
using the selected model. Requires tiktoken.

Usage:
    python model_decider.py [--jobs jobs.json] [--model gpt-4o]
"""
import argparse
import json
import os
import sys

try:
    import tiktoken
except ImportError:
    tiktoken = None


PRICE_PER_1K_TOKENS = {
    "gpt-4o": {"input": 0.005, "output": 0.015},
    "gpt-4-turbo": {"input": 0.01, "output": 0.03},
    "gpt-3.5-turbo": {"input": 0.0005, "output": 0.0015},
}


def count_tokens(text: str, model: str) -> int:
    if tiktoken is None:
        # rough estimate: ~4 chars per token
        return len(text) // 4
    try:
        enc = tiktoken.encoding_for_model(model)
    except KeyError:
        enc = tiktoken.get_encoding("cl100k_base")
    return len(enc.encode(text))


def main():
    parser = argparse.ArgumentParser(description="Estimate API cost for job evaluation.")
    parser.add_argument("--jobs", default="jobs.json", help="Path to jobs JSON file")
    parser.add_argument("--model", default="gpt-4o", help="OpenAI model name")
    parser.add_argument("--resume", default="resume.txt", help="Path to resume text file")
    args = parser.parse_args()

    if tiktoken is None:
        print("Warning: tiktoken not installed. Using rough character estimate.")
        print("Install with: pip install tiktoken")

    if not os.path.exists(args.jobs):
        print(f"Jobs file not found: {args.jobs}")
        sys.exit(1)

    with open(args.jobs, "r", encoding="utf-8") as f:
        jobs = json.load(f)

    resume_text = ""
    if os.path.exists(args.resume):
        with open(args.resume, "r", encoding="utf-8") as f:
            resume_text = f.read()

    job_text = "\n".join([json.dumps(j, ensure_ascii=False) for j in jobs])
    full_prompt = resume_text + "\n" + job_text

    token_count = count_tokens(full_prompt, args.model)
    prices = PRICE_PER_1K_TOKENS.get(args.model, {"input": 0.01, "output": 0.03})
    estimated_cost = (token_count / 1000) * prices["input"]

    print(f"Model:           {args.model}")
    print(f"Jobs:            {len(jobs)}")
    print(f"Estimated tokens: {token_count:,}")
    print(f"Estimated cost:  ${estimated_cost:.4f} (input only, at ${prices['input']}/1K tokens)")


if __name__ == "__main__":
    main()
