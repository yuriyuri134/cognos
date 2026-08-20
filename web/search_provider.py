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
import os
import re
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
    return (href.group(1) if href else ""), title


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
        url, title = match.group(1), _strip_tags(match.group(2))
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

    if provider == "duckduckgo":
        page = await _fetch(f"https://lite.duckduckgo.com/lite/?q={query}")
        return _parse_duckduckgo(page, top)

    page = await _fetch(f"https://www.bing.com/search?q={query}&setlang=zh-hans")
    return _parse_bing(page, top)


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
    lines.append("[/检索材料]")
    return "\n".join(lines)
