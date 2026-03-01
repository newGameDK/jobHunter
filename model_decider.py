# model_decider.py
#
# Decides which OpenAI model to use based on the token count of the prompt.
#
# Recommended model choices:
#   - gpt-3.5-turbo      : fast and cheap; suitable for most prompts (≤ 4 096 tokens)
#   - gpt-3.5-turbo-16k  : larger context window for long prompts (≤ 16 385 tokens)
#   - gpt-4o-mini        : cost-effective GPT-4 class model with large context window
#
# Adjust the thresholds below to match your preferred models and rate limits.

import tiktoken

MODEL_SMALL = "gpt-3.5-turbo"
MODEL_LARGE = "gpt-3.5-turbo-16k"
TOKEN_THRESHOLD = 3500  # switch to the large model above this many tokens


def count_tokens(text: str, model: str = MODEL_SMALL) -> int:
    """Return the number of tokens in *text* for the given model."""
    try:
        enc = tiktoken.encoding_for_model(model)
    except KeyError:
        enc = tiktoken.get_encoding("cl100k_base")
    return len(enc.encode(text))


def decide_model(prompt_text: str) -> str:
    """Return the appropriate model name based on prompt token count."""
    tokens = count_tokens(prompt_text)
    model = MODEL_LARGE if tokens > TOKEN_THRESHOLD else MODEL_SMALL
    print(f"  [model_decider] tokens={tokens} → using {model}")
    return model
