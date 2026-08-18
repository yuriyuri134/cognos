import os
import time
import httpx

api_key = open(r"c:\Users\lnp\Documents\Playground\cognos\web\.env", encoding="utf-8").read()
key = ""
for line in api_key.splitlines():
    if line.startswith("GEMINI_API_KEY="):
        key = line.split("=", 1)[1].strip().strip("'\"")

proxy = "http://127.0.0.1:7897"

models = ["gemini-2.5-flash", "gemini-2.5-pro", "gemini-3.5-flash", "gemini-3.6-flash", "gemini-3.7-flash", "gemini-flash-latest"]
for m in models:
    t0 = time.time()
    url = f"https://generativelanguage.googleapis.com/v1beta/models/{m}:generateContent?key={key}"
    payload = {"contents": [{"role": "user", "parts": [{"text": "hi"}]}]}
    try:
        with httpx.Client(proxy=proxy, timeout=30.0) as client:
            res = client.post(url, json=payload)
        dur = round(time.time() - t0, 2)
        print(f"{m:25s} | HTTP {res.status_code} | {dur}s | {res.text[:80]}")
    except Exception as e:
        dur = round(time.time() - t0, 2)
        print(f"{m:25s} | EXCEPTION | {dur}s | {e}")
