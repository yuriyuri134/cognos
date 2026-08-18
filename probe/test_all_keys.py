import os
import httpx

proxy = "http://127.0.0.1:7897"

# 1. Anthropic
ant_key = os.environ.get("ANTHROPIC_API_KEY")
if ant_key:
    url = "https://api.anthropic.com/v1/messages"
    headers = {
        "x-api-key": ant_key,
        "anthropic-version": "2023-06-01",
        "content-type": "application/json",
    }
    payload = {
        "model": "claude-3-5-haiku-20241022",
        "max_tokens": 50,
        "messages": [{"role": "user", "content": "hi"}],
    }
    try:
        with httpx.Client(proxy=proxy, timeout=10.0) as client:
            res = client.post(url, headers=headers, json=payload)
        print(f"Anthropic (Haiku): HTTP {res.status_code} | {res.text[:100]}")
    except Exception as e:
        print("Anthropic Exception:", e)

# 2. OpenAI
oa_key = os.environ.get("OPENAI_API_KEY")
if oa_key:
    url = "https://api.openai.com/v1/chat/completions"
    headers = {"Authorization": f"Bearer {oa_key}", "Content-Type": "application/json"}
    payload = {"model": "gpt-4o-mini", "messages": [{"role": "user", "content": "hi"}], "max_tokens": 50}
    try:
        with httpx.Client(proxy=proxy, timeout=10.0) as client:
            res = client.post(url, headers=headers, json=payload)
        print(f"OpenAI: HTTP {res.status_code} | {res.text[:100]}")
    except Exception as e:
        print("OpenAI Exception:", e)

# 3. Dashscope (Qwen)
ds_key = os.environ.get("DASHSCOPE_API_KEY")
if ds_key:
    url = "https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions"
    headers = {"Authorization": f"Bearer {ds_key}", "Content-Type": "application/json"}
    payload = {"model": "qwen-plus", "messages": [{"role": "user", "content": "hi"}], "max_tokens": 50}
    try:
        with httpx.Client(timeout=10.0) as client:
            res = client.post(url, headers=headers, json=payload)
        print(f"Dashscope: HTTP {res.status_code} | {res.text[:100]}")
    except Exception as e:
        print("Dashscope Exception:", e)
