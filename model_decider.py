"""
model_decider.py – Selects the appropriate OpenAI model based on prompt length.

Uses tiktoken for accurate token counting if available; falls back to a
character-based heuristic otherwise.
"""

GPT3_MODEL = "gpt-3.5-turbo"
GPT4_MODEL = "gpt-4"
GPT3_TOKEN_LIMIT = 3800   # leave headroom for the response


def count_tokens_approx(text):
    """Rough token estimate: ~4 characters per token."""
    return len(text) // 4


def count_tokens(text, model=GPT3_MODEL):
    try:
        import tiktoken
        enc = tiktoken.encoding_for_model(model)
        return len(enc.encode(text))
    except (ImportError, KeyError, ValueError):
        return count_tokens_approx(text)


def decide_model(prompt_text):
    """Return the model name to use for the given prompt."""
    token_count = count_tokens(prompt_text)
    if token_count > GPT3_TOKEN_LIMIT:
        return GPT4_MODEL
    return GPT3_MODEL
