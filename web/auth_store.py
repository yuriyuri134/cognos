"""多用户账号存储（SQLite 投影层）。

原则：
- 账号是普通软件对象（登录身份），不是 Subject/主体本体。
- 密码只存 PBKDF2-SHA256 哈希 + 盐；本层不保存明文口令。
- 会话令牌是随机 43 字符 URL-safe 字符串，哈希后入库，前端只持明文 Cookie。
- 本文件不涉及 raw occurrence；raw 真相源仍只有第一发生场.jsonl。
"""

from __future__ import annotations

import hashlib
import hmac
import secrets
import sqlite3
import uuid
from contextlib import contextmanager
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Iterator


_PBKDF2_ITERATIONS = 240_000
_TOKEN_TTL_HOURS = 24 * 14


def _now() -> str:
    return datetime.now(timezone.utc).isoformat()


def _hash_password(password: str, salt: bytes) -> bytes:
    return hashlib.pbkdf2_hmac("sha256", password.encode("utf-8"), salt, _PBKDF2_ITERATIONS)


def _token_hash(token: str) -> str:
    return hashlib.sha256(token.encode("utf-8")).hexdigest()


_SCHEMA = """
CREATE TABLE IF NOT EXISTS accounts (
    id TEXT PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    salt TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS auth_tokens (
    token_hash TEXT PRIMARY KEY,
    account_id TEXT NOT NULL REFERENCES accounts(id),
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS auth_tokens_account ON auth_tokens(account_id);
"""


class AuthError(ValueError):
    """用户名/密码/令牌类错误；消息可展示给用户。"""


class AuthStore:
    def __init__(self, path: Path) -> None:
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        with self._conn() as conn:
            conn.executescript(_SCHEMA)

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.path, timeout=10)
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA foreign_keys = ON")
        return conn

    @contextmanager
    def _conn(self) -> Iterator[sqlite3.Connection]:
        conn = self._connect()
        try:
            with conn:
                yield conn
        finally:
            conn.close()

    # ---------- 账号 ----------

    def create_account(self, username: str, password: str, display_name: str = "") -> dict[str, Any]:
        username = (username or "").strip().lower()
        if not 3 <= len(username) <= 32 or not username.replace("_", "").replace("-", "").isalnum():
            raise AuthError("用户名需为 3-32 位字母/数字/下划线/连字符")
        if len(password) < 6 or len(password) > 128:
            raise AuthError("密码需为 6-128 位")
        salt = secrets.token_bytes(16)
        digest = _hash_password(password, salt)
        account = {
            "id": uuid.uuid4().hex,
            "username": username,
            "display_name": (display_name or username).strip()[:80] or username,
            "salt": salt.hex(),
            "password_hash": digest.hex(),
            "created_at": _now(),
        }
        with self._conn() as conn:
            try:
                conn.execute(
                    "INSERT INTO accounts (id, username, display_name, salt, password_hash, created_at) "
                    "VALUES (?,?,?,?,?,?)",
                    (account["id"], account["username"], account["display_name"],
                     account["salt"], account["password_hash"], account["created_at"]),
                )
            except sqlite3.IntegrityError as exc:
                raise AuthError("用户名已被使用") from exc
        with self._conn() as conn:
            row = conn.execute("SELECT * FROM accounts WHERE id = ?", (account["id"],)).fetchone()
        return self._account_public(row)  # type: ignore[arg-type]

    def verify_login(self, username: str, password: str) -> dict[str, Any] | None:
        username = (username or "").strip().lower()
        with self._conn() as conn:
            row = conn.execute(
                "SELECT * FROM accounts WHERE username = ?", (username,)
            ).fetchone()
        if row is None:
            # 恒定时间比较失败分支：仍做一次哈希，避免用户名枚举计时差
            _hash_password(password, secrets.token_bytes(16))
            return None
        expected = bytes.fromhex(row["password_hash"])
        computed = _hash_password(password, bytes.fromhex(row["salt"]))
        if not hmac.compare_digest(expected, computed):
            return None
        return self._account_public(row)

    # ---------- 令牌 ----------

    def issue_token(self, account_id: str) -> str:
        token = secrets.token_urlsafe(32)
        expires = (datetime.now(timezone.utc) + timedelta(hours=_TOKEN_TTL_HOURS)).isoformat()
        with self._conn() as conn:
            conn.execute(
                "INSERT INTO auth_tokens (token_hash, account_id, expires_at, created_at) VALUES (?,?,?,?)",
                (_token_hash(token), account_id, expires, _now()),
            )
        return token

    def account_for_token(self, token: str) -> dict[str, Any] | None:
        if not token:
            return None
        digest = _token_hash(token)
        with self._conn() as conn:
            row = conn.execute(
                """SELECT a.*, t.expires_at AS token_expires_at
                   FROM auth_tokens t JOIN accounts a ON a.id = t.account_id
                   WHERE t.token_hash = ?""",
                (digest,),
            ).fetchone()
            if row is None:
                return None
            if datetime.fromisoformat(row["token_expires_at"]) <= datetime.now(timezone.utc):
                conn.execute("DELETE FROM auth_tokens WHERE token_hash = ?", (digest,))
                return None
        return self._account_public(row)

    def logout_token(self, token: str) -> None:
        with self._conn() as conn:
            conn.execute("DELETE FROM auth_tokens WHERE token_hash = ?", (_token_hash(token),))

    def get_account(self, account_id: str) -> dict[str, Any] | None:
        with self._conn() as conn:
            row = conn.execute("SELECT * FROM accounts WHERE id = ?", (account_id,)).fetchone()
        return self._account_public(row) if row is not None else None

    def list_accounts(self) -> list[dict[str, Any]]:
        with self._conn() as conn:
            rows = conn.execute("SELECT * FROM accounts ORDER BY created_at").fetchall()
        return [self._account_public(r) for r in rows]

    @staticmethod
    def _account_public(row: sqlite3.Row | dict[str, Any]) -> dict[str, Any]:
        return {
            "id": row["id"],
            "username": row["username"],
            "display_name": row["display_name"],
            "created_at": row["created_at"],
        }
