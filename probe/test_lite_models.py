import os
import httpx

api_key = open(r"c:\Users\lnp\Documents\Playground\cognos\web\.env", encoding="utf-8").read()
key = ""
for line in api_key.splitlines():
    if line.startswith("GEMINI_API_KEY="):
        key = line.split("=", 1)[1].strip().strip("'\"")

proxy = "http://127.0.0.1:7897"
url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent?key={key}"
# Also test gemini-3.5-flash-lite and gemini-3.1-flash-lite
for m in ["gemini-2.5-flash-lite", "gemini-3.5-flash-lite", "gemini-3.1-flash-lite", "gemini-3.5-flash"]:
    u = f"https://generativelanguage.googleapis.com/v1beta/models/{m}:generateContent?key={key}"
    payload = {"contents": [{"role": "user", "parts": [{"text": "Reply with one word: ok"}]}]}
    try:
        with httpx.Client(proxy=proxy, timeout=10.0) as client:
            res = client.post(u, json=payload)
        print(f"{m:25s} | HTTP {res.status_code} | {res.text[:120]}")
    except Exception as e:
        print(f"{m:25s} | Exception | {e}")
