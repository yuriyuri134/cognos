#!/usr/bin/env python3
"""Cognos 空间的 MCP server：让外面的 agent（Claude Code、Codex……）走进 Cognos 里的一片空间。

和 Hermes 在 Cognos 里用的是同一套动作：进来（拿到入口）、留下尝试、留下读法、在痕迹里找，
写下依据的想法（hold_premise）、对别人的想法提出反对（hit_premise）、回应别人的反对（answer_hit），
挂上没完的（hang_open）、走之前说停在哪（stop_here），
在交给它的事里交回一处的当前内容（write_content）。
它写下的东西都记在 COGNOS_SUBJECT 这个名字下。

只用标准库，stdio 上一行一个 JSON-RPC 消息。环境变量：
  COGNOS_URL           默认 http://127.0.0.1:8780
  COGNOS_USER          Nextcloud 用户，默认 cognos-admin
  COGNOS_APP_PASSWORD  Nextcloud「设置 → 安全」里建的应用密码（必填）
  COGNOS_SUBJECT       走进来的主体叫什么，默认 Claude Code
  COGNOS_TOOLS         只开哪几个工具，逗号分隔；不设就全开（claude_bridge.py 用它藏掉 enter_space，入口已经给了）
"""
from __future__ import annotations

import contextlib
import hashlib
import tempfile
from pathlib import Path
import base64
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid

BASE = os.environ.get("COGNOS_URL", "http://127.0.0.1:8780").rstrip("/")
USER = os.environ.get("COGNOS_USER", "cognos-admin")
PASSWORD = os.environ.get("COGNOS_APP_PASSWORD", "")
SUBJECT = os.environ.get("COGNOS_SUBJECT", "Claude Code")
BOUND_ID = os.environ.get("COGNOS_BOUND_SPACE_ID", "")
BOUND_TYPE = os.environ.get("COGNOS_BOUND_SPACE_TYPE", "creation")
BOUND_RUN_ID = os.environ.get("COGNOS_BOUND_RUN_ID", "")
RUN_BOUND_TOOLS = {"check_changes", "note_basis", "write_content", "adopt", "update_work_item", "split_work_item"}
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
            "走进一片空间，拿到它的入口：你在哪、Cognos 为了什么、这里的意图、以前谁试过什么、停在哪、"
            "事实、各人的读法、哪里还空着。动手之前先进来。message 填这次叫你来时说的话。"
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "message": {"type": "string", "description": "这次叫你来时说的话（可不填）"},
            },
            "required": ["space_id"],
        },
    },
    {
        "name": "leave_trace",
        "description": (
            "在这片空间里留下一条发生。尝试用 kind=attempt，stopped_at、touched_reality 可选。"
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
                "ref_id": {"type": "integer", "description": "kind=correction 时被更正那一条的 #id"},
                "status": {"type": "string", "description": "kind=correction 时那一条的新状态：confirmed / rejected / withdrawn"},
            },
            "required": ["space_id", "content"],
        },
    },
    {
        "name": "add_reading",
        "description": "留下你对这片空间的理解。它和别人说的话分开放；你之前的读法不会被删，只会被标为已替换。",
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "content": {"type": "string"},
                "refs": {"type": "array", "items": {"type": "integer"}, "description": "读的是哪几条 #id"},
            },
            "required": ["space_id", "content"],
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
            "写下你现在依据的一个想法，可以连上它涉及的代码或做法（governs）。"
            "别人可以对它提出反对，有反对时你会被叫回来。"
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "content": {"type": "string", "description": "前提本身，一句话说清"},
                "governs": {"type": "string", "description": "它管着什么：哪段代码（路径、类、方法）、哪个做法、哪个判断"},
            },
            "required": ["space_id", "content"],
        },
    },
    {
        "name": "hit_premise",
        "description": (
            "对这片空间里别人写下的想法提出反对（入口「想法和反对」里的 #id），写清理由："
            "事实、矛盾、反例或现实结果。写那个想法的人会被叫回来。"
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "ref_id": {"type": "integer", "description": "那个想法的 #id"},
                "content": {"type": "string", "description": "反对的理由"},
            },
            "required": ["space_id", "ref_id", "content"],
        },
    },
    {
        "name": "answer_hit",
        "description": (
            "回应别人对你写下的想法提出的反对。act 写这个想法现在怎么样："
            "还成立、放下、改成新的（要写 new_premise）。"
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "hit_id": {"type": "integer", "description": "那一下的 #id"},
                "act": {"type": "string", "enum": ["还成立", "放下", "改成新的"]},
                "content": {"type": "string", "description": "你的回应"},
                "new_premise": {"type": "string", "description": "act=改成新的 时，新的想法"},
                "governs": {"type": "string", "description": "act=改成新的 时新想法涉及什么；不填就沿用旧的"},
            },
            "required": ["space_id", "hit_id", "act", "content"],
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
            },
            "required": ["space_id", "content"],
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
            },
            "required": ["space_id", "open_id", "act", "moved"],
        },
    },
    {
        "name": "start_work",
        "description": "在一片空间（项目里的一个位置）登记你在动它：开始一次运行。返回 run_id，交回时要用。入口里给你的在生效的决定会记成这次的输入。",
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "purpose": {"type": "string", "description": "这次在动什么（一句话）"},
            },
            "required": ["space_id"],
        },
    },
    {
        "name": "check_changes",
        "description": "看看起步以后这里变了什么：有没有你输入里没有的新决定，你报过依据的内容有没有被改。",
        "inputSchema": {"type": "object", "properties": {"run_id": {"type": "string"}}, "required": ["run_id"]},
    },
    {
        "name": "hand_back",
        "description": (
            "做完了，把结果交回这片空间。起步以后有了你没看到的新决定、或你依据的内容被改了，第一次调用会先把差异给你看，"
            "不交；看完（可以改你的结果）再调一次，带 acknowledged=true。"
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "run_id": {"type": "string", "description": "start_work 给的 run_id"},
                "content": {"type": "string", "description": "交回的结果"},
                "acknowledged": {"type": "boolean", "description": "已经看过差异"},
            },
            "required": ["space_id", "run_id", "content"],
        },
    },
    {
        "name": "adopt",
        "description": (
            "在这一处采用一样东西的一版：讨论或材料里的一段回答（object_type=message，object_id 是那句的 id），"
            "或当前内容的某一版（object_type=location_content，object_id 是位置 id，object_version 是版本号）。"
            "交给你的事允许在这里采用时直接采用；否则只记成你的提议，等用户定。"
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "run_id": {"type": "string", "description": "本次实际委托的运行编号，自动委托已绑定"},
                "object_type": {"type": "string", "enum": ["message", "location_content"]},
                "object_id": {"type": "string"},
                "object_version": {"type": "string", "description": "location_content 的版本号"},
                "basis": {"type": "string", "description": "为什么采用"},
            },
            "required": ["space_id", "object_type", "object_id"],
        },
    },
    {
        "name": "note_basis",
        "description": (
            "记下这次真正依据的一样东西：一条在生效的决定（decision_quote 照抄原话里的一段），"
            "或这一处当前内容的某一版（revision）。可以不记。"
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "run_id": {"type": "string", "description": "本次实际委托的运行编号，自动委托已绑定"},
                "decision_quote": {"type": "string", "description": "那条决定原话里的一段，照抄"},
                "revision": {"type": "integer", "description": "这一处当前内容的版本号"},
                "note": {"type": "string", "description": "怎么依据的（可不填）"},
            },
            "required": ["space_id"],
        },
    },
    {
        "name": "write_content",
        "description": (
            "在交给你的事里，交回这一处（项目里的一个位置）改好的当前内容全文。只有这次委托允许改内容时才收；"
            "会作为你署名的新版本保存。交出去以后有人改过这一处，不会覆盖，你的版本留着等人比较。"
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "space_id": SPACE_ID,
                "space_type": SPACE_TYPE,
                "run_id": {"type": "string", "description": "本次实际委托的运行编号，自动委托已绑定"},
                "content": {"type": "string", "description": "改好的当前内容全文（不是改动说明）"},
            },
            "required": ["space_id", "content"],
        },
    },
]

TOOLS += [
    {"name": "list_work_items", "description": "读取这一处明确登记的工作：范围、负责人、交付、验收、状态和拆分来源。运行返回不等于工作完成。",
     "inputSchema": {"type": "object", "properties": {"space_id": SPACE_ID, "space_type": SPACE_TYPE}, "required": ["space_id"]}},
    {"name": "get_work_item", "description": "读取一份工作的原版本、拆分来路、子项、原委托与结果。只读，不代用户改变工作安排。",
     "inputSchema": {"type": "object", "properties": {"space_id": SPACE_ID, "space_type": SPACE_TYPE, "work_id": {"type": "string"}}, "required": ["space_id", "work_id"]}},
    {"name": "preview_work_split", "description": "按各子项的范围、负责人、交付、验收预览工作拆分的具体后果；只预览，不提交、不复制或启动委托。",
     "inputSchema": {"type": "object", "properties": {"space_id": SPACE_ID, "space_type": SPACE_TYPE, "work_id": {"type": "string"},
         "parts": {"type": "array", "minItems": 2, "maxItems": 12, "items": {"type": "object", "properties": {
             k: {"type": "string"} for k in ("description", "owner", "deliverable", "acceptance")}, "required": ["description"]}}}, "required": ["space_id", "work_id", "parts"]}},
]

TOOLS += [
    {"name":"list_attempts","description":"读取关联到这一处的尝试、当前简介及阶段记录；这些作者的总结不代表工作已完成或结果已验证。", "inputSchema":{"type":"object","properties":{"space_id":SPACE_ID,"space_type":SPACE_TYPE},"required":["space_id"]}},
    {"name":"get_attempt","description":"按稳定身份读取一份尝试的简介、旧版本和历次阶段总结及覆盖范围。仅限与当前位置关联的尝试。", "inputSchema":{"type":"object","properties":{"space_id":SPACE_ID,"space_type":SPACE_TYPE,"attempt_id":{"type":"string"}},"required":["space_id","attempt_id"]}},
]

TOOLS += [
    {"name":"update_work_item","description":"按明确委托维护关联工作的完整安排或记录结果，署名为实际 AI。必须读当前版本；未获得工作维护权限时拒绝。新决定/依据变化先返回差异，确认同一差异后带 acknowledged=true 重试。",
     "inputSchema":{"type":"object","properties":{"space_id":SPACE_ID,"space_type":SPACE_TYPE,"work_id":{"type":"string"},"run_id":{"type":"string","description":"本次委托运行；经桥时自动附加"},
        "expected_revision":{"type":"integer"}, **{k:{"type":"string"} for k in ("description","owner","deliverable","acceptance","evidence","note")},
        "state":{"type":"string","enum":["open","in_progress","blocked","done","cancelled"]},"acknowledged":{"type":"boolean"}},
        "required":["space_id","work_id","expected_revision","description","owner","deliverable","acceptance","state"]}},
    {"name":"split_work_item","description":"在明确允许维护这份工作的委托内，按 preview_work_split 的 basis 确认拆分。保留原工作、旧委托与结果；子项不继承授权或自动执行，拆分后本委托不再维护原工作。",
     "inputSchema":{"type":"object","properties":{"space_id":SPACE_ID,"space_type":SPACE_TYPE,"work_id":{"type":"string"},"run_id":{"type":"string"},"basis":{"type":"string"},
        "parts":{"type":"array","minItems":2,"maxItems":12,"items":{"type":"object","properties":{k:{"type":"string"} for k in ("description","owner","deliverable","acceptance")},"required":["description"]}},
        "acknowledged":{"type":"boolean"}},"required":["space_id","work_id","parts","basis"]}},
]

NORM_SPACE = {"type": "string", "description": "只在 Norm 自己的对话里填：要读、要改的是哪个空间（read_space 不填时会列出来）"}
TOOLS += [
    {"name": "read_space",
     "description": ("读你所在的 Norm 空间现在的样子：它是什么、agent 在这里的做法（交互对象不在时做什么、怎样算往前走、什么时候找他）、"
                     "用哪几块和每一块现在的内容、还等着他定的事、最近发生的，以及放进这里的 Initium 对话原话（linked）。动手改之前先读。"),
     "inputSchema": {"type": "object", "properties": {"space_id": SPACE_ID, "space_type": SPACE_TYPE, "norm_space": NORM_SPACE,
                     "linked_id": {"type": "integer", "description": "只读放进这里的某一段对话的全文（linked 里的 creation_id）；原话被缩短时用"}},
                     "required": ["space_id"]}},
    {"name": "write_part",
     "description": ("改这个空间的一块：stages（演变）、branches（拆解）、relations（关系）、compare（并排对照）、doc（文档）。"
                     "content 是这一块改完以后的全部内容，格式见 read_space 返回的 formats；先读再写，别丢了原来的。"
                     "你提出、还要交互对象定的一段演变，带上 proposed: true。改理想、改目标、把一段理解改成新的，不要直接写，用 note_for_you 提出来。"),
     "inputSchema": {"type": "object", "properties": {
         "space_id": SPACE_ID, "space_type": SPACE_TYPE, "norm_space": NORM_SPACE,
         "part": {"type": "string", "enum": ["stages", "branches", "relations", "compare", "doc"]},
         "content": {"description": "这一块的全部内容：doc 是一段文字，其余是 JSON"}},
         "required": ["space_id", "part", "content"]}},
    {"name": "note_for_you",
     "description": ("给交互对象留一条，他打开这个空间时最先看到。kind=happened：你做了什么、发生了什么，一句话，读的人不用再问；"
                     "kind=decide：只有他能定的一件，写清要定什么，options 给两三个选项（每个几个字）。"),
     "inputSchema": {"type": "object", "properties": {
         "space_id": SPACE_ID, "space_type": SPACE_TYPE, "norm_space": NORM_SPACE,
         "kind": {"type": "string", "enum": ["happened", "decide"]},
         "text": {"type": "string"},
         "options": {"type": "array", "items": {"type": "string"}}},
         "required": ["space_id", "kind", "text"]}},
]

ONLY = {t.strip() for t in os.environ.get("COGNOS_TOOLS", "").split(",") if t.strip()}
if ONLY:
    TOOLS = [t for t in TOOLS if t["name"] in ONLY]
if BOUND_RUN_ID:
    fixed_fields = {"space_id", "space_type", "run_id"}
    for tool in TOOLS:
        schema = tool["inputSchema"]
        schema["properties"] = {key: value for key, value in schema.get("properties", {}).items() if key not in fixed_fields}
        schema["required"] = [key for key in schema.get("required", []) if key not in fixed_fields]


class CognosAPIError(RuntimeError):
    def __init__(self,status,data,message):
        self.status=status
        self.data=data
        super().__init__(f"Cognos 返回 {status}：{message}")


def call_api(method: str, path: str, params: dict | None = None, body: dict | None = None, url: str | None = None):
    if not PASSWORD:
        raise RuntimeError("没有设 COGNOS_APP_PASSWORD：在 Nextcloud「设置 → 安全」里建一个应用密码")
    url = url or API + path
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
            parsed = json.loads(text)
            data = parsed.get("ocs", {}).get("data", parsed)
            message = data.get("message", text[:500])
        except (ValueError, KeyError, TypeError, AttributeError):
            data, message = {}, text[:500]
        raise CognosAPIError(error.code, data, message) from None
    return payload.get("ocs", {}).get("data", payload)



def _handoff_file(args):
    identity = [BASE, USER, SUBJECT, space_path(args), str(args["run_id"])]
    key = hashlib.sha256(json.dumps(identity).encode()).hexdigest()
    root = Path(os.environ.get("COGNOS_MCP_STATE_DIR", str(Path.home() / ".local/state/cognos-mcp")))
    root.mkdir(parents=True, exist_ok=True, mode=0o700)
    return root / (key + ".json")


@contextlib.contextmanager
def _handoff_lock(file):
    with file.with_suffix(".lock").open("a+b") as lock:
        if lock.tell() == 0:
            lock.write(b"0")
            lock.flush()
        lock.seek(0)
        if os.name == "nt":
            import msvcrt
            msvcrt.locking(lock.fileno(), msvcrt.LK_LOCK, 1)
        else:
            import fcntl
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX)
        try:
            yield
        finally:
            lock.seek(0)
            if os.name == "nt":
                msvcrt.locking(lock.fileno(), msvcrt.LK_UNLCK, 1)
            else:
                fcntl.flock(lock.fileno(), fcntl.LOCK_UN)


def _save_handoff(file, state):
    fd, temporary = tempfile.mkstemp(dir=file.parent, prefix=".handoff-")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            json.dump(state, stream, ensure_ascii=False)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, file)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def handoff(name, args):
    file = _handoff_file(args)
    with _handoff_lock(file):
        state = json.loads(file.read_text(encoding="utf-8")) if file.exists() else {}
        if name == "hand_back" and state.get("pending"):
            if state["pending"]["content"] != args["content"]:
                raise RuntimeError("上次交回已有留存，请先用上次的原文重试确认，不能覆盖尚未确认的结果。")
            if not state.get("delivered"):
                call_api("POST", space_path(args) + "/messages", body=state["pending"])
                state["delivered"] = True
                _save_handoff(file, state)
            return "已交回。"
        run_id = urllib.parse.quote(str(args["run_id"]), safe="")
        drift = call_api("GET", "", None, None, f"{BASE}/ocs/v2.php/apps/cognos/api/v1.0/runs/{run_id}/drift")
        token = drift.get("drift_token")
        lines = [f"- 新决定（起步时未进入输入）：「{d['content']}」" for d in drift["decisions_missing"]]
        for b in drift["basis_changed"]:
            lines.append(f"- 依据的「{b['title']}」版本 {b['version']}，现在是版本 {b['current']}（{b['editor']} 改的）")
            lines += [f"    删了：{x}" for x in b.get("removed", [])]
            lines += [f"    加了：{x}" for x in b.get("added", [])]
        confirmed = bool(args.get("acknowledged") and token and state.get("shown_token") == token)
        if name == "check_changes" or (lines and not confirmed):
            state["shown_token"] = token
            _save_handoff(file, state)
            if not lines:
                return "当前检查没有发现新决定或已上报依据的版本变化；未上报依据不在检查范围内。"
            suffix = "" if name == "check_changes" else "\n还没交。请看这次的差异，需要时修改结果，再带 acknowledged=true 交回。"
            return "起步以后这里变了：\n" + "\n".join(lines) + suffix
        body = {"subject": SUBJECT, "run_id": args["run_id"], "result_id": uuid.uuid4().hex,
                "content": args["content"], "acknowledged_drift": confirmed,
                "drift_token": token if confirmed else None}
        state["pending"] = body
        _save_handoff(file, state)
        call_api("POST", space_path(args) + "/messages", body=body)
        state["delivered"] = True
        _save_handoff(file, state)
        return "已交回。"


def write_work(name,args):
    path=space_path(args)+"/work-items/"+urllib.parse.quote(str(args["work_id"]),safe="")
    excluded={"space_id","space_type","work_id","acknowledged"}
    body={k:v for k,v in args.items() if k not in excluded}
    body["subject"]=SUBJECT
    identity=[BASE,USER,SUBJECT,name,space_path(args),str(args["work_id"]),body]
    body["request_id"]="wm-"+hashlib.sha256(json.dumps(identity,sort_keys=True,ensure_ascii=False).encode()).hexdigest()[:60]
    file=_handoff_file(args)
    with _handoff_lock(file):
        state=json.loads(file.read_text(encoding="utf-8")) if file.exists() else {}
        body["acknowledged"]=bool(args.get("acknowledged") and state.get("shown_token"))
        body["drift_token"]=state.get("shown_token") if body["acknowledged"] else None
        try:
            data=call_api("PUT" if name=="update_work_item" else "POST",path+("/split" if name=="split_work_item" else ""),body=body)
        except CognosAPIError as error:
            drift=error.data.get("drift") if isinstance(error.data,dict) else None
            if error.status!=409 or not isinstance(drift,dict):raise
            state["shown_token"]=drift["drift_token"]
            _save_handoff(file,state)
            lines=["本次没有修改工作。起步以后有变化："]
            lines += ["- 新决定：「"+d["content"]+"」" for d in drift["decisions_missing"]]
            for b in drift["basis_changed"]:
                lines.append(f"- 依据的「{b['title']}」由版本 {b['version']} 变为 {b['current']}")
                lines += ["  删了："+line for line in b.get("removed",[])]
                lines += ["  加了："+line for line in b.get("added",[])]
            return "\n".join(lines)+"\n请面对这些变化，需要时修改安排，再带 acknowledged=true 提交。"
    return json.dumps({**data,"request_id":body["request_id"]},ensure_ascii=False)


# 这片空间不在项目的某个位置上时，尝试和工作都没有登记的地方：Cognos 回 404。这不是出错，照实告诉它
NO_LOCATION = "这里不在项目的任何位置上，没有登记的{what}。（不是出错：这种空间本来就没有这一项。）"


def space_path(args: dict) -> str:
    if BOUND_ID and (str(args.get("space_type") or "creation") != BOUND_TYPE or str(args["space_id"]) != BOUND_ID):
        raise RuntimeError("这次连接只接入指定的项目位置，不能改用另一片空间。")
    space_type = urllib.parse.quote(str(args.get("space_type") or "creation"), safe="")
    space_id = urllib.parse.quote(str(args["space_id"]), safe="")
    return f"/{space_type}/{space_id}"


def run_tool(name: str, args: dict) -> str:
    if ONLY and name not in ONLY:
        raise RuntimeError(f"这里没开这个工具：{name}")
    if BOUND_RUN_ID:
        if not BOUND_ID or BOUND_TYPE != "creation" or not re.fullmatch(r"[a-f0-9]{32}", BOUND_RUN_ID):
            raise RuntimeError("自动委托的运行或位置绑定无效。")
        if name in ("start_work", "hand_back"):
            raise RuntimeError("这次运行已登记，由桥统一交回。")
        if "run_id" in args and args["run_id"] != BOUND_RUN_ID:
            raise RuntimeError("这次连接只允许实际委托绑定的运行。")
        args = {"space_id": BOUND_ID, "space_type": BOUND_TYPE, **args}
        if name in RUN_BOUND_TOOLS:
            args = {**args, "run_id": BOUND_RUN_ID}
    if name in ("update_work_item","split_work_item"):
        args={**args,"run_id":args.get("run_id") or os.environ.get("COGNOS_RUN_ID","")}
        if not args["run_id"]:raise RuntimeError("需要实际委托的 run_id；不能用新登记的自由运行代替维护授权")
    if BOUND_ID and "space_id" in args:
        space_path(args)  # Reject a mismatched space before any network disclosure.
    if BOUND_ID and name in ("check_changes", "hand_back", "update_work_item", "split_work_item"):
        args = {**args, "space_id": BOUND_ID, "space_type": BOUND_TYPE}
        if name == "hand_back":
            # A confirmed local receipt must remain usable when the server is temporarily offline.
            file = _handoff_file(args)
            with _handoff_lock(file):
                cached = json.loads(file.read_text(encoding="utf-8")) if file.exists() else {}
            pending = cached.get("pending", {})
            if cached.get("delivered") and pending.get("run_id") == args["run_id"] and pending.get("subject") == SUBJECT:
                return handoff(name, args)
        run_id = urllib.parse.quote(str(args["run_id"]), safe="")
        run = call_api("GET", "", url=f"{BASE}/ocs/v2.php/apps/cognos/api/v1.0/runs/{run_id}")["run"]
        if run["space_type"] != BOUND_TYPE or str(run["space_id"]) != BOUND_ID or run["subject"] != SUBJECT:
            raise RuntimeError("这次运行不属于当前指定位置和主体，不能读取或交回它。")
    if not BOUND_ID and name == "check_changes" and "space_id" not in args:
        run_id = urllib.parse.quote(str(args["run_id"]), safe="")
        run = call_api("GET", "", url=f"{BASE}/ocs/v2.php/apps/cognos/api/v1.0/runs/{run_id}")["run"]
        if run["subject"] != SUBJECT:
            raise RuntimeError("这次运行不属于当前主体。")
        args = {**args, "space_id": str(run["space_id"]), "space_type": run["space_type"]}
    if not BOUND_ID and name in ("update_work_item","split_work_item"):
        run_id=urllib.parse.quote(str(args["run_id"]),safe="")
        run=call_api("GET","",url=f"{BASE}/ocs/v2.php/apps/cognos/api/v1.0/runs/{run_id}")["run"]
        if run["subject"]!=SUBJECT or str(run["space_id"])!=str(args["space_id"]) or run["space_type"]!=(args.get("space_type") or "creation"):
            raise RuntimeError("运行不属于当前主体和位置")
    if name in ("update_work_item","split_work_item"):
        return write_work(name,args)
    if name == "list_spaces":
        spaces = call_api("GET", "")["spaces"]
        if BOUND_ID:
            spaces = [s for s in spaces if s["type"] == BOUND_TYPE and str(s["id"]) == BOUND_ID]
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
        return f"已留下 #{trace['id']}（{trace['kind']}）。"
    if name == "add_reading":
        body = {k: v for k, v in args.items() if k not in ("space_id", "space_type")}
        body["subject"] = SUBJECT
        reading = call_api("POST", space_path(args) + "/readings", body=body)["reading"]
        return f"已留下读法 #{reading['id']}。"
    if name == "hold_premise":
        body = {k: v for k, v in args.items() if k not in ("space_id", "space_type")}
        body.update({"kind": "premise", "subject": SUBJECT})
        trace = call_api("POST", space_path(args) + "/traces", body=body)["trace"]
        return f"已写下 #{trace['id']}。有人反对时你会被叫回来。"
    if name == "hit_premise":
        body = {k: v for k, v in args.items() if k not in ("space_id", "space_type")}
        body.update({"kind": "hit", "subject": SUBJECT})
        data = call_api("POST", space_path(args) + "/traces", body=body)
        pushback = data.get("pushback") or {}
        return f"已对 #{body.get('ref_id')} 提出反对，记为 #{data['trace']['id']}。{pushback.get('message', '')}"
    if name == "answer_hit":
        body = {k: v for k, v in args.items() if k not in ("space_id", "space_type", "hit_id")}
        body["subject"] = SUBJECT
        data = call_api("POST", space_path(args) + f"/hits/{int(args['hit_id'])}/answer", body=body)
        text = f"已回应 #{int(args['hit_id'])}：{data['answer']['act']}（#{data['answer']['id']}）。"
        if data.get("premise"):
            text += f"新的想法是 #{data['premise']['id']}。"
        return text
    if name == "hang_open":
        body = {"kind": "open", "subject": SUBJECT, "content": args.get("content", "")}
        trace = call_api("POST", space_path(args) + "/traces", body=body)["trace"]
        return f"已挂上 #{trace['id']}。它会拉着下一次：手上这件做完，Cognos 会从它接着叫。"
    if name == "stop_here":
        body = {
            "kind": "stop", "subject": SUBJECT, "ref_id": int(args["open_id"]), "act": args.get("act"),
            "content": args.get("moved", ""), "stopped_at": args.get("edge"),
        }
        trace = call_api("POST", space_path(args) + "/traces", body=body)["trace"]
        then = {"接着": "Cognos 会从这个口子叫下一次", "卡住": "这一件停下，等能给的那一件", "完了": "这一件停下，收不收由交互对象定"}
        return f"已记下停在哪 #{trace['id']}（{trace['act']}）。{then.get(trace['act'], '')}。"
    if name in ("list_attempts", "get_attempt"):
        path=space_path(args)+"/attempts"
        if name=="get_attempt":path+="/"+urllib.parse.quote(str(args["attempt_id"]),safe="")
        try:
            return json.dumps(call_api("GET",path),ensure_ascii=False)
        except CognosAPIError as error:
            if name == "list_attempts" and error.status == 404:
                return NO_LOCATION.format(what="尝试")
            raise
    if name in ("list_work_items", "get_work_item", "preview_work_split"):
        path = space_path(args) + "/work-items"
        if name != "list_work_items":
            path += "/" + urllib.parse.quote(str(args["work_id"]), safe="")
        try:
            if name == "preview_work_split":
                data = call_api("POST", path + "/split/preview", body={"parts": args["parts"]})
            else:
                data = call_api("GET", path)
        except CognosAPIError as error:
            if name == "list_work_items" and error.status == 404:
                return NO_LOCATION.format(what="工作")
            raise
        return json.dumps(data, ensure_ascii=False, indent=1)
    if name in ("read_space", "write_part", "note_for_you"):
        path = space_path(args) + "/norm"
        extra = {"norm_space": args["norm_space"]} if args.get("norm_space") else {}
        if name == "read_space":
            if args.get("linked_id"):
                extra["linked_id"] = int(args["linked_id"])
            data = call_api("GET", path, extra or None)
            return json.dumps(data, ensure_ascii=False, indent=1)
        if name == "write_part":
            data = call_api("POST", path + "/part", body={**extra, "part": args["part"], "content": args["content"], "subject": SUBJECT})
        else:
            data = call_api("POST", path + "/note", body={**extra, "kind": args["kind"], "text": args["text"],
                                                          "options": args.get("options") or [], "subject": SUBJECT})
        return data["message"]
    if name == "start_work":
        data = call_api("POST", space_path(args) + "/runs", body={"subject": SUBJECT, "purpose": args.get("purpose", "")})
        return f"已登记，run_id 是 {data['run']['id']}。做完用 hand_back 交回时带上它。"
    if name in ("check_changes", "hand_back"):
        return handoff(name, args)
    if name == "adopt":
        body = {k: v for k, v in args.items() if k not in ("space_id", "space_type")}
        body["subject"] = SUBJECT
        return call_api("POST", space_path(args) + "/adoptions", body=body)["message"]
    if name == "note_basis":
        body = {k: v for k, v in args.items() if k not in ("space_id", "space_type")}
        body["subject"] = SUBJECT
        return call_api("POST", space_path(args) + "/basis", body=body)["message"]
    if name == "write_content":
        body = {"subject": SUBJECT, "content": args.get("content", "")}
        if args.get("run_id"):
            body["run_id"] = args["run_id"]
        data = call_api("POST", space_path(args) + "/content", body=body)
        return data["message"]
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
