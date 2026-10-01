"""Web 检索提供方（地区无关）。

用于项目推进时，给参与者注入可验证的联网材料。默认走 Bing HTML 检索
（本机已实测 200），不依赖 Google（中国大陆 IP 被区域封锁）。

纪律：
- 检索结果只是"材料 / 候选来源"，不自动升格为事实；
- 参与者的回答与来源分开记录（search_sources），可回指核查；
- 检索失败不吞错误，返回 SearchUnavailableError（由调用方决定降级）。
"""

from __future__ import annotations

import html as _html
import hashlib
import base64
import binascii
import os
import re
from datetime import datetime, timezone
from urllib.parse import parse_qs, quote_plus, unquote, urlparse, urlunparse
from typing import Any

import httpx


class SearchUnavailableError(RuntimeError):
    """检索通道不可用（网络/解析/经办方拦截）。"""


_USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/124.0 Safari/537.36"
)


def _strip_tags(text: str | None) -> str:
    return _html.unescape(re.sub(r"<[^>]+>", " ", text or "")).strip()


def _parse_bing_h2(block: str) -> tuple[str, str]:
    """返回 (url, title)，h2 内的第一个链接。"""
    match = re.search(r'<h2[^>]*>\s*(.*?)</h2>', block, re.S)
    if not match:
        return "", ""
    inner = match.group(1)
    href = re.search(r'href="([^"]+)"', inner)
    title = _strip_tags(inner)
    return _canonical_url(href.group(1) if href else ""), title


def _canonical_url(value: str) -> str:
    """去掉搜索跳转包装和明显的追踪参数，保留可回指 URL。"""

    value = _html.unescape((value or "").strip())
    if not value:
        return ""
    parsed = urlparse(value)
    query = parse_qs(parsed.query)
    # Bing/搜索页常把真实地址放在 u 参数中。
    for key in ("url", "u"):
        candidate = query.get(key, [""])[0]
        # Bing 的 u 参数常见为 a1 + URL-safe/base64 编码的真实地址。
        if candidate.startswith("a1"):
            try:
                encoded = candidate[2:]
                encoded += "=" * (-len(encoded) % 4)
                decoded = base64.urlsafe_b64decode(encoded).decode("utf-8")
                if decoded.startswith(("http://", "https://")):
                    candidate = decoded
            except (ValueError, UnicodeDecodeError, binascii.Error):
                pass
        if candidate.startswith(("http://", "https://")):
            value = unquote(candidate)
            parsed = urlparse(value)
            break
    if parsed.scheme not in {"http", "https"} or not parsed.netloc:
        return value
    query = parse_qs(parsed.query)
    kept = [(key, vals[0]) for key, vals in query.items()
            if not key.lower().startswith(("utm_", "msclkid", "gclid"))
            for vals in [vals]]
    clean_query = "&".join(f"{quote_plus(k)}={quote_plus(v)}" for k, v in kept)
    return urlunparse((parsed.scheme, parsed.netloc, parsed.path or "/", "", clean_query, ""))


async def _fetch(url: str, *, timeout: float = 15.0) -> str:
    headers = {
        "User-Agent": _USER_AGENT,
        "Accept-Language": "zh-CN,zh;q=0.9,en;q=0.7",
    }
    async with httpx.AsyncClient(timeout=timeout, follow_redirects=True) as client:
        response = await client.get(url, headers=headers)
        if response.status_code != 200:
            raise SearchUnavailableError(
                f"检索源返回 HTTP {response.status_code}（url={url}）"
            )
        return response.text


def _extract_page_text(html_text: str, *, max_chars: int = 12000) -> str:
    """提取网页可读正文的有限片段；原始 HTML 仍由调用方另行决定是否保存。"""

    text = re.sub(r"<script\b[^>]*>.*?</script\s*>", " ", html_text or "", flags=re.I | re.S)
    text = re.sub(r"<style\b[^>]*>.*?</style\s*>", " ", text, flags=re.I | re.S)
    text = re.sub(r"<noscript\b[^>]*>.*?</noscript\s*>", " ", text, flags=re.I | re.S)
    text = _strip_tags(text)
    text = re.sub(r"\s+", " ", text).strip()
    return text[:max_chars]


def _parse_bing(html_text: str, top: int) -> list[dict[str, str]]:
    results: list[dict[str, str]] = []
    for block in re.findall(r'<li class="b_algo".*?</li>', html_text, re.S):
        url, title = _parse_bing_h2(block)
        if not url or not title:
            continue
        snippet = ""
        m = re.search(r'<p[^>]*>(.*?)</p>', block, re.S)
        if m:
            snippet = _strip_tags(m.group(1))
        results.append({"title": title, "url": url, "snippet": snippet})
        if len(results) >= top:
            break
    return results


def _parse_duckduckgo(html_text: str, top: int) -> list[dict[str, str]]:
    results: list[dict[str, str]] = []
    # DuckDuckGo lite：<a class="result-link" href="...">title</a>
    for match in re.finditer(
        r'<a[^>]*class="result-link"[^>]*href="([^"]+)"[^>]*>(.*?)</a>', html_text, re.S
    ):
        url, title = _canonical_url(match.group(1)), _strip_tags(match.group(2))
        results.append({"title": title, "url": url, "snippet": ""})
        if len(results) >= top:
            break
    return results


async def web_search(query: str, *, top: int | None = None) -> list[dict[str, str]]:
    """执行检索并返回 [{title, url, snippet}]。

    提供方由 SEARCH_PROVIDER 控制（bing 默认 / duckduckgo），条数 SEARCH_RESULTS。
    """

    query = query.strip()
    if not query:
        return []
    provider = (os.environ.get("SEARCH_PROVIDER", "bing") or "bing").lower()
    top = int(os.environ.get("SEARCH_RESULTS", "5")) if top is None else top

    encoded = quote_plus(query)
    if provider == "duckduckgo":
        page = await _fetch(f"https://lite.duckduckgo.com/lite/?q={encoded}")
        results = _parse_duckduckgo(page, top)
    else:
        page = await _fetch(f"https://www.bing.com/search?q={encoded}&setlang=zh-hans")
        results = _parse_bing(page, top)
    stamp = datetime.now(timezone.utc).isoformat()
    return [dict(item, provider=provider, query=query, retrieved_at=stamp) for item in results]


async def enrich_search_sources(
    sources: list[dict[str, Any]], *, max_sources: int | None = None,
    max_chars: int = 12000,
) -> list[dict[str, Any]]:
    """按显式配置打开候选来源，保存正文提取状态，不把失败伪装成成功。"""

    limit = max_sources if max_sources is not None else int(os.environ.get("SEARCH_OPEN_RESULTS", "3"))
    enriched: list[dict[str, Any]] = []
    raw_excerpt_limit = max(0, int(os.environ.get("SEARCH_RAW_HTML_EXCERPT_CHARS", "4000")))
    for source in sources:
        item = dict(source)
        url = item.get("url", "")
        if len(enriched) >= limit or not url:
            enriched.append(item)
            continue
        try:
            raw = await _fetch(url, timeout=float(os.environ.get("SEARCH_PAGE_TIMEOUT", "12")))
            item["page_text"] = _extract_page_text(raw, max_chars=max_chars)
            item["extraction_status"] = "ok" if item["page_text"] else "empty"
            # 保存可复核的响应指纹和有限原文片段；完整 HTML 不直接注入模型上下文，
            # 以免页面脚本、隐藏文本或提示注入混进认识材料。
            item["raw_content_sha256"] = hashlib.sha256(raw.encode("utf-8", errors="replace")).hexdigest()
            item["raw_content_length"] = len(raw)
            item["raw_content_excerpt"] = raw[:raw_excerpt_limit]
            item["raw_content_truncated"] = len(raw) > raw_excerpt_limit
        except Exception as exc:
            item["page_text"] = ""
            item["extraction_status"] = "failed"
            item["extraction_error"] = f"{type(exc).__name__}: {exc}"
        enriched.append(item)
    return enriched


def format_search_material(sources: list[dict[str, str]]) -> str:
    """把检索来源格式化为注入参与者的材料块。"""

    lines = ["[联网检索材料（来源可核查，不自动视为事实）]"]
    for index, source in enumerate(sources, start=1):
        url = source.get("url") or ""
        title = source.get("title") or url
        snippet = (source.get("snippet") or "").strip()
        lines.append(f"{index}. {title}\n   url: {url}")
        if snippet:
            lines.append(f"   摘要: {snippet}")
        page_text = (source.get("page_text") or "").strip()
        if page_text:
            lines.append(f"   页面摘录（仅作材料，需回看原文）: {page_text}")
        if source.get("extraction_status") == "failed":
            lines.append("   页面正文：读取失败，不能据此推断正文内容")
    lines.append("[/检索材料]")
    return "\n".join(lines)
