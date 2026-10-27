#!/usr/bin/env python3
"""Cognos 空间的 MCP server：让外面的 agent（Claude Code、Codex……）走进 Cognos 里的一片空间。

和 Hermes 在 Cognos 里用的是同一套动作：进来（拿到入口）、留下尝试、留下读法、在痕迹里找，
押上前提（hold_premise）、顶别人押着的（hit_premise）、面对顶到自己的（answer_hit），
挂上没完的（hang_open）、走之前说停在哪（stop_here）。
它写下的东西一律记为〔AI〕和 COGNOS_SUBJECT 这个名字，不能代替交互对象写原话。

只用标准库，stdio 上一行一个 JSON-RPC 消息。环境变量：
  COGNOS_URL           默认 http://127.0.0.1:8780
  COGNOS_USER          Nextcloud 用户，默认 cognos-admin
  COGNOS_APP_PASSWORD  Nextcloud「设置 → 安全」里建的应用密码（必填）
  COGNOS_SUBJECT       走进来的主体叫什么，默认 Claude Code
  COGNOS_TOOLS         只开哪几个工具，逗号分隔；不设就全开（claude_bridge.py 用它藏掉 enter_space，入口已经给了）
"""
from __future__ import annotations

import base64
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get("COGNOS_URL", "http://127.0.0.1:8780").rstrip("/")
USER = os.environ.get("COGNOS_USER", "cognos-admin")
PASSWORD = os.environ.get("COGNOS_APP_PASSWORD", "")
SUBJECT = os.environ.get("COGNOS_SUBJECT", "Claude Code")
API = BASE + "/ocs/v2.php/apps/cognos/api/v1.0/spaces"

SPACE_ID = {"type": "string", "description": "空间 id（list_spaces 里的 id）"}
SPACE_TYPE = {"type": "string", "description": "空间类型，现在只有 creation", "default": "creation"}

TOOLS = [
    {
        "name": "list_spaces",
        "description": "列出 Cognos 里你能走进去的空间（现在是创立空间）。",
        "inputSchema": {"type": "object", "properties": {}},
    },
    {
        "name": "enter_space",
        "description": (
            "走进一片空间，拿到它的入口：你在哪、Cognos 为了什么、这里的意图（原话）、以前谁试过什么、停在哪、"
            "事实、各人的读法、哪里还空着。动手之前先进来。message 填这次叫你来的原话。"
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "message": {"type": "string", "description": "这次叫你来的原话（可不填）"},
            },
            "required": ["space_id"],
        },
    },
    {
        "name": "leave_trace",
        "description": (
            "在这片空间里留下一条发生。做了一次尝试就用 kind=attempt，写明停在哪、碰没碰过现实。"
            "要更正某一条，用 kind=correction 加 ref_id 和 status。"
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "kind": {"type": "string", "description": "attempt（默认）、fact、material、correction……自由文本"},
                "content": {"type": "string", "description": "正文"},
                "about": {"type": "string", "description": "和哪个意图有关，用入口里意图「标为」的那个词"},
                "stopped_at": {"type": "string", "description": "尝试停在哪里、为什么停"},
                "touched_reality": {"type": "boolean", "description": "碰过现实为 true，只在模型上推为 false"},
                "act": {"type": "string", "description": "这条在做什么：陈述、推测、提问、举例……"},
                "confidence": {"type": "integer", "description": "你的把握，0-100"},
                "ref_id": {"type": "integer", "description": "kind=correction 时被更正那一条的 #id"},
                "status": {"type": "string", "description": "kind=correction 时那一条的新状态：confirmed / rejected / withdrawn"},
            },
            "required": ["space_id", "content", "confidence"],
        },
    },
    {
        "name": "add_reading",
        "description": "留下你对这片空间的读法。它和原话分开放、标是你的；你之前的读法不会被删，只会被标为已替换。",
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "content": {"type": "string"},
                "confidence": {"type": "integer", "description": "你的把握，0-100"},
                "refs": {"type": "array", "items": {"type": "integer"}, "description": "读的是哪几条 #id"},
            },
            "required": ["space_id", "content", "confidence"],
        },
    },
    {
        "name": "search_traces",
        "description": "在这片空间留下的全部发生里找（入口只列了最近的尝试）。",
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "query": {"type": "string"},
                "limit": {"type": "integer", "default": 20},
            },
            "required": ["space_id"],
        },
    },
]

TOOLS += [
    {
        "name": "hold_premise",
        "description": (
            "押上一条你自己的前提：你凭它在想、在做的那一条，连上它管着的代码或做法（governs）。"
            "押着的东西能被顶回来；被顶到时你会被叫回来面对它。"
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "content": {"type": "string", "description": "前提本身，一句话说清"},
                "governs": {"type": "string", "description": "它管着什么：哪段代码（路径、类、方法）、哪个做法、哪个判断"},
                "confidence": {"type": "integer", "description": "你的把握，0-100"},
            },
            "required": ["space_id", "content", "confidence"],
        },
    },
    {
        "name": "hit_premise",
        "description": (
            "顶这片空间里别人押着的一条前提（入口「这里别人押着的」里的 #id），写清是什么把它顶回来的："
            "事实、矛盾、反例、现实的结果。押的那位会被叫回来面对。"
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "ref_id": {"type": "integer", "description": "被顶的那条前提的 #id"},
                "content": {"type": "string", "description": "顶回来的是什么"},
                "confidence": {"type": "integer", "description": "你的把握，0-100"},
            },
            "required": ["space_id", "ref_id", "content", "confidence"],
        },
    },
    {
        "name": "answer_hit",
        "description": (
            "面对顶到你押着的前提的一下：站住（写清凭什么还站得住）、碎了（写清碎在哪）、"
            "重新切（写出新的前提；在 Cognos 自己的空间里，还要去改它管着的代码）。"
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "hit_id": {"type": "integer", "description": "那一下的 #id"},
                "act": {"type": "string", "enum": ["站住", "碎了", "重新切"]},
                "content": {"type": "string", "description": "你怎么面对的，这一下之后什么变了"},
                "new_premise": {"type": "string", "description": "act=重新切 时，新的前提"},
                "governs": {"type": "string", "description": "act=重新切 时新前提管着什么；不填就沿用旧的"},
                "confidence": {"type": "integer", "description": "你的把握，0-100"},
            },
            "required": ["space_id", "hit_id", "act", "content", "confidence"],
        },
    },
]

TOOLS += [
    {
        "name": "hang_open",
        "description": (
            "把一件还没完的挂在这片空间里：你的一个尝试站起来，它还有后续。挂着的会拉着下一次，"
            "Cognos 会从它接着叫（不用等交互对象说话）。只挂真的还没完、而且有人或有东西能从它接着走的。"
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "content": {"type": "string", "description": "还没完的是什么，一句话说清"},
                "confidence": {"type": "integer", "description": "你的把握，0-100"},
            },
            "required": ["space_id", "content", "confidence"],
        },
    },
    {
        "name": "stop_here",
        "description": (
            "走之前说清这一次停在哪件没完的哪里。act=接着：下一个你自己够得着的口子（edge），"
            "Cognos 会马上从它叫下一次；act=卡住：只有交互对象、钱或现实能给的那一件（edge）；act=完了：凭什么算完。"
            "moved 写这一次往前走了什么（和上一次比多了什么、哪里变了），不是做了哪些动作。"
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "open_id": {"type": "integer", "description": "那件没完的 #id"},
                "act": {"type": "string", "enum": ["接着", "卡住", "完了"]},
                "moved": {"type": "string", "description": "这一次往前走了什么"},
                "edge": {"type": "string", "description": "接着：下一个口子，具体到一进来就能动手；卡住：卡在哪一件"},
                "confidence": {"type": "integer", "description": "你的把握，0-100"},
            },
            "required": ["space_id", "open_id", "act", "moved", "confidence"],
        },
    },
]

ONLY = {t.strip() for t in os.environ.get("COGNOS_TOOLS", "").split(",") if t.strip()}
if ONLY:
    TOOLS = [t for t in TOOLS if t["name"] in ONLY]


def call_api(method: str, path: str, params: dict | None = None, body: dict | None = None):
    if not PASSWORD:
        raise RuntimeError("没有设 COGNOS_APP_PASSWORD：在 Nextcloud「设置 → 安全」里建一个应用密码")
    url = API + path
    if params:
        url += "?" + urllib.parse.urlencode({k: v for k, v in params.items() if v is not None})
    data = json.dumps(body, ensure_ascii=False).encode("utf-8") if body is not None else None
    token = base64.b64encode(f"{USER}:{PASSWORD}".encode("utf-8")).decode("ascii")
    request = urllib.request.Request(url, data=data, method=method, headers={
        "Authorization": "Basic " + token,
        "OCS-APIRequest": "true",
        "Accept": "application/json",
        "Content-Type": "application/json",
    })
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            payload = json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as error:
        text = error.read().decode("utf-8", "replace")
        try:
            message = json.loads(text)["ocs"]["data"].get("message", text)
        except (ValueError, KeyError, TypeError, AttributeError):
            message = text[:500]
        raise RuntimeError(f"Cognos 返回 {error.code}：{message}") from None
    return payload.get("ocs", {}).get("data", payload)


def space_path(args: dict) -> str:
    space_type = urllib.parse.quote(str(args.get("space_type") or "creation"), safe="")
    space_id = urllib.parse.quote(str(args["space_id"]), safe="")
    return f"/{space_type}/{space_id}"


def run_tool(name: str, args: dict) -> str:
    if ONLY and name not in ONLY:
        raise RuntimeError(f"这里没开这个工具：{name}")
    if name == "list_spaces":
        spaces = call_api("GET", "")["spaces"]
        if not spaces:
            return "还没有空间。"
        return "\n".join(f"- {s['type']} {s['id']}：{s['title']}" for s in spaces)
    if name == "enter_space":
        data = call_api("GET", space_path(args) + "/enter", {"subject": SUBJECT, "message": args.get("message")})
        return data["text"]
    if name == "leave_trace":
        body = {k: v for k, v in args.items() if k not in ("space_id", "space_type")}
        body["subject"] = SUBJECT
        trace = call_api("POST", space_path(args) + "/traces", body=body)["trace"]
        return f"已留下 #{trace['id']}（〔AI {SUBJECT}〕{trace['kind']}）。"
    if name == "add_reading":
        body = {k: v for k, v in args.items() if k not in ("space_id", "space_type")}
        body["subject"] = SUBJECT
        reading = call_api("POST", space_path(args) + "/readings", body=body)["reading"]
        return f"已留下读法 #{reading['id']}。"
    if name == "hold_premise":
        body = {k: v for k, v in args.items() if k not in ("space_id", "space_type")}
        body.update({"kind": "premise", "subject": SUBJECT})
        trace = call_api("POST", space_path(args) + "/traces", body=body)["trace"]
        return f"已押上 #{trace['id']}（〔AI {SUBJECT}〕）。被顶到时你会被叫回来面对它。"
    if name == "hit_premise":
        body = {k: v for k, v in args.items() if k not in ("space_id", "space_type")}
        body.update({"kind": "hit", "subject": SUBJECT})
        data = call_api("POST", space_path(args) + "/traces", body=body)
        pushback = data.get("pushback") or {}
        return f"已顶 #{body.get('ref_id')}，记为 #{data['trace']['id']}。{pushback.get('message', '')}"
    if name == "answer_hit":
        body = {k: v for k, v in args.items() if k not in ("space_id", "space_type", "hit_id")}
        body["subject"] = SUBJECT
        data = call_api("POST", space_path(args) + f"/hits/{int(args['hit_id'])}/answer", body=body)
        text = f"已面对 #{int(args['hit_id'])}：{data['answer']['act']}（#{data['answer']['id']}）。"
        if data.get("premise"):
            text += f"新的前提是 #{data['premise']['id']}。"
        return text
    if name == "hang_open":
        body = {"kind": "open", "subject": SUBJECT, "content": args.get("content", ""), "confidence": args.get("confidence")}
        trace = call_api("POST", space_path(args) + "/traces", body=body)["trace"]
        return f"已挂上 #{trace['id']}。它会拉着下一次：手上这件做完，Cognos 会从它接着叫。"
    if name == "stop_here":
        body = {
            "kind": "stop", "subject": SUBJECT, "ref_id": int(args["open_id"]), "act": args.get("act"),
            "content": args.get("moved", ""), "stopped_at": args.get("edge"), "confidence": args.get("confidence"),
        }
        trace = call_api("POST", space_path(args) + "/traces", body=body)["trace"]
        then = {"接着": "Cognos 会从这个口子叫下一次", "卡住": "这一件停下，等能给的那一件", "完了": "这一件停下，收不收由交互对象定"}
        return f"已记下停在哪 #{trace['id']}（{trace['act']}）。{then.get(trace['act'], '')}。"
    if name == "search_traces":
        traces = call_api("GET", space_path(args) + "/traces", {"q": args.get("query", ""), "limit": args.get("limit", 20)})["traces"]
        if not traces:
            return "没找到。"
        return json.dumps(traces, ensure_ascii=False, indent=1)
    raise RuntimeError(f"没有这个工具：{name}")


def reply(message_id, result=None, error=None) -> None:
    message = {"jsonrpc": "2.0", "id": message_id}
    if error is not None:
        message["error"] = error
    else:
        message["result"] = result
    sys.stdout.buffer.write((json.dumps(message, ensure_ascii=False) + "\n").encode("utf-8"))
    sys.stdout.buffer.flush()


def handle(message: dict) -> None:
    method = message.get("method")
    message_id = message.get("id")
    if message_id is None:
        return  # 通知，不用回
    if method == "initialize":
        reply(message_id, {
            "protocolVersion": message.get("params", {}).get("protocolVersion", "2024-11-05"),
            "capabilities": {"tools": {}},
            "serverInfo": {"name": "cognos-space", "version": "0.1.0"},
        })
    elif method == "ping":
        reply(message_id, {})
    elif method == "tools/list":
        reply(message_id, {"tools": TOOLS})
    elif method == "tools/call":
        params = message.get("params", {})
        try:
            text = run_tool(params.get("name", ""), params.get("arguments") or {})
            reply(message_id, {"content": [{"type": "text", "text": text}]})
        except Exception as error:  # 工具出错要回给 agent 看，不让 server 退出
            reply(message_id, {"content": [{"type": "text", "text": str(error)}], "isError": True})
    else:
        reply(message_id, error={"code": -32601, "message": f"Method not found: {method}"})


def main() -> None:
    for raw in sys.stdin.buffer:
        line = raw.decode("utf-8", "replace").strip()
        if not line:
            continue
        try:
            message = json.loads(line)
        except ValueError:
            continue
        handle(message)


if __name__ == "__main__":
    main()
