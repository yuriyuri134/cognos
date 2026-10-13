"""Nextcloud Talk bot bridge for the existing Cognos conversation service.

This is an integration boundary, not a second conversation store. Talk message
identity is kept as external metadata on the append-only occurrence; it is never
silently converted into a local Cognos account.
"""

from __future__ import annotations

import asyncio
import hashlib
import hmac
import json
import re
import sqlite3
import uuid
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable, Iterator
from urllib.parse import urljoin

import httpx


class TalkBridgeError(RuntimeError):
    """A safe-to-log integration error without request secrets."""


class TalkBridgeStore:
    """Small projection for webhook idempotency and delivery recovery."""

    _PROCESSING_LEASE_SECONDS = 600

    def __init__(self, path: Path) -> None:
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        with self._conn() as conn:
            conn.executescript(
                """
                CREATE TABLE IF NOT EXISTS talk_bridge_events (
                    event_key TEXT PRIMARY KEY,
                    payload_hash TEXT NOT NULL,
                    status TEXT NOT NULL,
                    reply TEXT NOT NULL DEFAULT '',
                    occurrence_id TEXT NOT NULL DEFAULT '',
                    error TEXT NOT NULL DEFAULT '',
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                );
                """
            )

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.path, timeout=10)
        conn.row_factory = sqlite3.Row
        return conn

    @contextmanager
    def _conn(self) -> Iterator[sqlite3.Connection]:
        conn = self._connect()
        try:
            with conn:
                yield conn
        finally:
            conn.close()

    def claim(self, event_key: str, payload_hash: str) -> dict[str, Any]:
        now = _now()
        with self._conn() as conn:
            row = conn.execute(
                "SELECT * FROM talk_bridge_events WHERE event_key = ?", (event_key,)
            ).fetchone()
            if row is None:
                conn.execute(
                    "INSERT INTO talk_bridge_events "
                    "(event_key,payload_hash,status,created_at,updated_at) VALUES (?,?,?,?,?)",
                    (event_key, payload_hash, "processing", now, now),
                )
                return {"status": "claimed", "event_key": event_key}
            if not hmac.compare_digest(row["payload_hash"], payload_hash):
                raise TalkBridgeError("Talk event key was reused with different payload")
            if row["status"] == "failed":
                conn.execute(
                    "UPDATE talk_bridge_events SET status='processing', error='', updated_at=? "
                    "WHERE event_key=?",
                    (now, event_key),
                )
                return {"status": "claimed", "event_key": event_key}
            if row["status"] == "reply_pending":
                return dict(row)
            if row["status"] == "processing":
                try:
                    age = (datetime.now(timezone.utc) - datetime.fromisoformat(row["updated_at"])).total_seconds()
                except (TypeError, ValueError):
                    age = 0
                if age > self._PROCESSING_LEASE_SECONDS:
                    conn.execute(
                        "UPDATE talk_bridge_events SET updated_at=? WHERE event_key=?",
                        (now, event_key),
                    )
                    return {"status": "claimed", "event_key": event_key}
            return dict(row)

    def finish(
        self,
        event_key: str,
        *,
        status: str,
        reply: str = "",
        occurrence_id: str = "",
        error: str = "",
    ) -> None:
        if status not in {"ignored", "completed", "failed", "reply_pending"}:
            raise ValueError("invalid Talk bridge event status")
        with self._conn() as conn:
            conn.execute(
                "UPDATE talk_bridge_events SET status=?, reply=?, occurrence_id=?, error=?, updated_at=? "
                "WHERE event_key=?",
                (status, reply, occurrence_id, error, _now(), event_key),
            )


def _now() -> str:
    return datetime.now(timezone.utc).isoformat()


def verify_signature(raw_body: bytes, random_value: str, signature: str, secret: str) -> bool:
    if not random_value or not signature or not secret:
        return False
    expected = hmac.new(
        secret.encode("utf-8"),
        (random_value.encode("utf-8") + raw_body),
        hashlib.sha256,
    ).hexdigest()
    return hmac.compare_digest(expected.lower(), signature.strip().lower())


def _json_content(value: Any) -> tuple[str, dict[str, Any]]:
    if isinstance(value, dict):
        return str(value.get("message") or ""), value
    if not isinstance(value, str):
        return str(value or ""), {}
    try:
        parsed = json.loads(value)
    except json.JSONDecodeError:
        return value, {}
    if isinstance(parsed, dict):
        return str(parsed.get("message") or ""), parsed
    return value, {}


def _talk_text(obj: dict[str, Any]) -> tuple[str, dict[str, Any]]:
    text, content = _json_content(obj.get("content", ""))
    parameters = content.get("parameters")
    if isinstance(parameters, dict):
        for key, value in parameters.items():
            if not isinstance(key, str) or not isinstance(value, dict):
                continue
            label = str(value.get("name") or value.get("id") or key)
            if not label.startswith("@"):
                label = "@" + label
            text = text.replace("{" + key + "}", label)
    return text.strip(), content


class TalkBridge:
    """Translate signed Talk bot events into existing Cognos turns."""

    def __init__(
        self,
        *,
        service: Any,
        store: TalkBridgeStore,
        secret: str,
        nextcloud_url: str,
        bot_name: str = "Cognos",
        default_target: str | None = None,
        room_projects: dict[str, str] | None = None,
        default_project_id: str | None = None,
        require_mention: bool = True,
        http_client_factory: Callable[[], httpx.AsyncClient] | None = None,
    ) -> None:
        if len(secret) < 40:
            raise ValueError("Talk bot secret must be at least 40 characters")
        parsed_url = nextcloud_url.rstrip("/") + "/"
        if not parsed_url.startswith(("http://", "https://")):
            raise ValueError("Nextcloud URL must use http or https")
        self.service = service
        self.store = store
        self.secret = secret
        self.nextcloud_url = parsed_url
        self.bot_name = bot_name.strip() or "Cognos"
        self.default_target = default_target
        self.room_projects = room_projects or {}
        self.default_project_id = default_project_id
        self.require_mention = require_mention
        self.http_client_factory = http_client_factory or (
            lambda: httpx.AsyncClient(timeout=httpx.Timeout(30.0, connect=5.0))
        )
        self._lock = asyncio.Lock()

    def verify_request(self, raw_body: bytes, random_value: str, signature: str) -> bool:
        return verify_signature(raw_body, random_value, signature, self.secret)

    async def handle(self, payload: dict[str, Any], raw_body: bytes) -> dict[str, Any]:
        event_key = self._event_key(payload, raw_body)
        claim = self.store.claim(event_key, hashlib.sha256(raw_body).hexdigest())
        if claim["status"] in {"completed", "ignored"}:
            return {
                "status": "duplicate",
                "event_id": event_key,
                "reply": claim.get("reply", ""),
            }
        if claim["status"] == "processing":
            # Another request may be handling it. The lock below makes this
            # process wait and then re-read the durable projection.
            async with self._lock:
                refreshed = self.store.claim(event_key, hashlib.sha256(raw_body).hexdigest())
                if refreshed["status"] == "completed":
                    return {"status": "duplicate", "event_id": event_key, "reply": refreshed.get("reply", "")}
                if refreshed["status"] == "ignored":
                    return {"status": "duplicate", "event_id": event_key}
                claim = refreshed
            if claim["status"] == "processing":
                return {"status": "processing", "event_id": event_key}

        async with self._lock:
            reply_persisted = False
            try:
                if claim["status"] == "reply_pending":
                    reply_text = str(claim.get("reply") or "").strip()
                    occurrence_id = str(claim.get("occurrence_id") or "")
                    room_token = self._room_token(payload)
                    if not reply_text or not room_token:
                        raise TalkBridgeError("Stored Talk reply is incomplete")
                    await self.send_reply(room_token, reply_text, event_key, reply_to=self._message_id(payload))
                    self.store.finish(event_key, status="completed", reply=reply_text, occurrence_id=occurrence_id)
                    return {"status": "completed", "event_id": event_key, "occurrence_id": occurrence_id, "reply": reply_text}
                extracted = self._extract(payload)
                if extracted is None:
                    self.store.finish(event_key, status="ignored")
                    return {"status": "ignored", "event_id": event_key}
                message, author, author_id, room_token, target, project_id = extracted
                result = await self.service.send(
                    message,
                    target=target,
                    project_id=project_id,
                    conversation_id=f"talk:{room_token}",
                    author=author,
                    external_source="nextcloud_talk",
                    external_event_id=event_key,
                    external_author_id=author_id,
                    external_room_id=room_token,
                )
                reply_record = result.get("reply") or {}
                reply_text = str(reply_record.get("content") or "").strip()
                occurrence_id = str(reply_record.get("id") or "")
                if not reply_text or not occurrence_id:
                    raise TalkBridgeError("Cognos returned an incomplete reply")
                # The raw turn already exists. Persist the pending delivery before an external request.
                self.store.finish(event_key, status="reply_pending", reply=reply_text, occurrence_id=occurrence_id)
                reply_persisted = True
                await self.send_reply(room_token, reply_text, event_key, reply_to=self._message_id(payload))
                self.store.finish(event_key, status="completed", reply=reply_text, occurrence_id=occurrence_id)
                return {
                    "status": "completed",
                    "event_id": event_key,
                    "occurrence_id": occurrence_id,
                    "reply": reply_text,
                }
            except Exception as exc:
                safe_error = f"{type(exc).__name__}: {str(exc)[:300]}"
                # Once the reply is persisted, keep it retryable without another model turn.
                if reply_persisted or claim.get("status") == "reply_pending":
                    raise
                self.store.finish(event_key, status="failed", error=safe_error)
                # A runtime failure must remain visible in Talk; the human input
                # is already durable in raw and this notification does not retry AI.
                room_token = self._room_token(payload)
                if room_token:
                    try:
                        await self.send_reply(
                            room_token,
                            "本次 Cognos 调用失败，原消息已保留，请稍后重试。",
                            event_key,
                            reply_to=self._message_id(payload),
                        )
                    except Exception:
                        pass
                raise

    async def send_reply(
        self,
        room_token: str,
        message: str,
        event_key: str,
        *,
        reply_to: int | None = None,
    ) -> None:
        message_body = f"{self.bot_name} · Cognos\n\n{message}"
        payload: dict[str, Any] = {
            "message": message_body,
            "referenceId": hashlib.sha256(event_key.encode()).hexdigest(),
        }
        if reply_to is not None:
            payload["replyTo"] = reply_to
        body = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        random_value = uuid.uuid4().hex + uuid.uuid4().hex
        # Talk 25 validates the response signature over random + message field,
        # while incoming webhook signatures cover the complete JSON body.
        signature = hmac.new(
            self.secret.encode("utf-8"),
            random_value.encode("utf-8") + message_body.encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()
        endpoint = urljoin(
            self.nextcloud_url,
            f"ocs/v2.php/apps/spreed/api/v1/bot/{room_token}/message",
        )
        async with self.http_client_factory() as client:
            response = await client.post(
                endpoint,
                content=body,
                headers={
                    "Content-Type": "application/json",
                    "Accept": "application/json",
                    "OCS-APIRequest": "true",
                    "X-Nextcloud-Talk-Bot-Random": random_value,
                    "X-Nextcloud-Talk-Bot-Signature": signature,
                },
            )
        if response.status_code not in {200, 201}:
            raise TalkBridgeError(f"Nextcloud Talk reply failed with HTTP {response.status_code}")

    def _room_token(self, payload: dict[str, Any]) -> str:
        target = payload.get("target") or {}
        return str(target.get("id") or "").strip()

    def _message_id(self, payload: dict[str, Any]) -> int | None:
        value = (payload.get("object") or {}).get("id")
        try:
            parsed = int(value)
        except (TypeError, ValueError):
            return None
        return parsed if parsed > 0 else None

    def _event_key(self, payload: dict[str, Any], raw_body: bytes) -> str:
        room = self._room_token(payload)
        obj = payload.get("object") or {}
        message_id = str(obj.get("id") or "")
        if room and message_id:
            return f"talk:{room}:{message_id}"
        return "talk:body:" + hashlib.sha256(raw_body).hexdigest()

    def _extract(self, payload: dict[str, Any]) -> tuple[str, str, str, str, str | None, str | None] | None:
        if payload.get("type") != "Create":
            return None
        actor = payload.get("actor") or {}
        obj = payload.get("object") or {}
        target_obj = payload.get("target") or {}
        room_token = str(target_obj.get("id") or "").strip()
        if not room_token:
            return None
        actor_id = str(actor.get("id") or "").strip()
        actor_name = str(actor.get("name") or actor_id or "Talk member").strip()
        if actor_id.lower().startswith("bots/") or actor_name.casefold() == self.bot_name.casefold():
            return None
        if str(obj.get("name") or "message") != "message":
            return None
        text, rich_content = _talk_text(obj)
        if not text:
            return None
        parameters = rich_content.get("parameters") if isinstance(rich_content, dict) else {}
        bot_mentioned = bool(re.search(r"@" + re.escape(self.bot_name) + r"\b", text, re.I))
        if isinstance(parameters, dict):
            bot_mentioned = bot_mentioned or any(
                isinstance(value, dict)
                and str(value.get("name") or "").casefold() == self.bot_name.casefold()
                for value in parameters.values()
            )
        if self.require_mention and not bot_mentioned:
            return None
        text = re.sub(r"@" + re.escape(self.bot_name) + r"\b", "", text, flags=re.I).strip()
        if not text:
            text = "请回应这条消息。"
        target = self.default_target
        for participant_id, participant in self.service.participants.items():
            if re.search(r"@" + re.escape(participant.name) + r"\b", text, re.I) or re.search(
                r"@" + re.escape(participant_id) + r"\b", text, re.I
            ):
                target = participant_id
                break
        project_id = self.room_projects.get(room_token) or self.default_project_id
        return text, actor_name, actor_id, room_token, target, project_id


def load_room_projects(raw: str | None) -> dict[str, str]:
    if not raw:
        return {}
    try:
        value = json.loads(raw)
    except json.JSONDecodeError:
        return {}
    return {str(k): str(v) for k, v in value.items()} if isinstance(value, dict) else {}


def parse_bool(value: str | None, default: bool = True) -> bool:
    if value is None:
        return default
    return value.strip().lower() not in {"0", "false", "no", "off"}
