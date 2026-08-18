import json
from pathlib import Path
import httpx

web_env = Path(r"c:\Users\lnp\Documents\Playground\cognos\web\.env")
api_key = ""
if web_env.exists():
    for line in web_env.read_text(encoding="utf-8").splitlines():
        if line.startswith("GEMINI_API_KEY="):
            api_key = line.split("=", 1)[1].strip().strip("'\"")

system_prompt = (
    "You are an autonomous engineering agent exploring a codebase.\n"
    "Available tools:\n"
    "- list_dir(path: str)\n"
    "- read_file(path: str)\n"
    "- write_file(path: str, content: str)\n"
    "- run_command(command: str)\n"
    "- run_pytest(test_path: str)\n"
    "- finish(summary: str)\n\n"
    "You MUST respond ONLY with a valid JSON object of the form:\n"
    "{\n"
    '  "thought": "<brief analysis and plan>",\n'
    '  "tool": "<tool_name>",\n'
    '  "arguments": { ... }\n'
    "}"
)

user_msg = "Task: Explore current project root and find where web server routes are defined."

for model in ["gemini-3.5-flash", "gemini-3.6-flash", "gemini-flash-latest", "gemini-3.7-flash"]:
    url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={api_key}"
    payload = {
        "systemInstruction": {"parts": [{"text": system_prompt}]},
        "contents": [{"role": "user", "parts": [{"text": user_msg}]}],
        "generationConfig": {
            "responseMimeType": "application/json",
            "temperature": 0.2,
        }
    }
    try:
        res = httpx.post(url, json=payload, timeout=20)
        print(f"Model {model}: HTTP {res.status_code}")
        if res.status_code == 200:
            candidate = res.json()["candidates"][0]["content"]["parts"]
            text = "".join(p.get("text", "") for p in candidate if not p.get("thought", False))
            if not text:
                text = "".join(p.get("text", "") for p in candidate)
            print("  Parsed output:\n ", text[:200])
            break
        else:
            print("  Error:", res.text[:200])
    except Exception as e:
        print("  Exception:", e)
