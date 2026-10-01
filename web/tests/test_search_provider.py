"""search_provider 离线测试：不访问网络，用既有 HTML 结构验证解析与格式化。"""

from __future__ import annotations

import asyncio
import unittest
from unittest.mock import patch

from search_provider import (
    SearchUnavailableError,
    _canonical_url,
    _extract_page_text,
    _fetch,
    enrich_search_sources,
    _parse_bing,
    _parse_duckduckgo,
    format_search_material,
    web_search,
)


CANONICAL_BING = """
<ol id="b_results">
  <li class="b_algo">
    <h2><a href="https://evidence.example/a">CERN 最新截面测量</a></h2>
    <p class="b_lineclamp4">对撞机最新结果摘要文字。</p>
  </li>
  <li class="b_algo">
    <h2><a href="https://news.example/b">LHC Run-3 数据发布</a></h2>
    <p>2026 年公开数据集与论文链接说明。</p>
  </li>
  <li class="b_noresult">no result marker should be ignored</li>
</ol>
"""


class ParseBingTest(unittest.TestCase):
    def test_canonical_url_removes_tracking_parameters(self) -> None:
        self.assertEqual(
            "https://example.com/article?a=1",
            _canonical_url("https://example.com/article?utm_source=x&a=1#part"),
        )

    def test_canonical_url_decodes_bing_a1_redirect(self) -> None:
        self.assertEqual(
            "https://example.com/article",
            _canonical_url("https://www.bing.com/ck/a?u=a1aHR0cHM6Ly9leGFtcGxlLmNvbS9hcnRpY2xl"),
        )

    def test_parse_bing_extracts_title_url_snippet(self) -> None:
        results = _parse_bing(CANONICAL_BING, top=5)
        self.assertEqual(2, len(results))
        self.assertEqual("CERN 最新截面测量", results[0]["title"])
        self.assertEqual("https://evidence.example/a", results[0]["url"])
        self.assertIn("对撞机最新结果摘要", results[0]["snippet"])
        self.assertNotIn("<", results[0]["snippet"])

    def test_parse_bing_top_limit(self) -> None:
        results = _parse_bing(CANONICAL_BING, top=1)
        self.assertEqual(1, len(results))
        self.assertEqual("https://evidence.example/a", results[0]["url"])


class ParseDuckDuckGoTest(unittest.TestCase):
    def test_parse_duckduckgo(self) -> None:
        html = (
            '<a class="result-link" href="https://ddg.example/1">DDG 结果一</a>'
            '<a class="result-link" href="https://ddg.example/2">DDG 结果二</a>'
        )
        results = _parse_duckduckgo(html, top=2)
        self.assertEqual(2, len(results))
        self.assertEqual("https://ddg.example/1", results[0]["url"])


class FormatMaterialTest(unittest.TestCase):
    def test_format_contains_source_and_boundary(self) -> None:
        sources = [{"title": "T", "url": "https://x/1", "snippet": "s"}]
        text = format_search_material(sources)
        self.assertIn("[联网检索材料", text)
        self.assertIn("https://x/1", text)
        self.assertIn("[/检索材料]", text)

    def test_format_includes_page_excerpt_and_failed_extraction_boundary(self) -> None:
        text = format_search_material([
            {"title": "T", "url": "https://x/1", "snippet": "s", "page_text": "正文"},
            {"title": "F", "url": "https://x/2", "extraction_status": "failed"},
        ])
        self.assertIn("页面摘录", text)
        self.assertIn("读取失败", text)


class ExtractPageTextTest(unittest.TestCase):
    def test_extract_page_text_drops_script_and_collapses_whitespace(self) -> None:
        self.assertEqual("标题 正文 内容", _extract_page_text(
            "<h1>标题</h1><script>secret()</script><p>正文\n内容</p>"
        ))


class EnrichSourcesTest(unittest.IsolatedAsyncioTestCase):
    async def test_enrich_preserves_extraction_and_raw_response_boundary(self) -> None:
        raw = "<html><script>x()</script><p>正文</p></html>"
        with patch("search_provider._fetch", return_value=raw):
            sources = await enrich_search_sources([
                {"title": "T", "url": "https://x/1", "snippet": "摘要"}
            ])
        item = sources[0]
        self.assertEqual("正文", item["page_text"])
        self.assertEqual("ok", item["extraction_status"])
        self.assertEqual(64, len(item["raw_content_sha256"]))
        self.assertEqual(len(raw), item["raw_content_length"])
        self.assertIn("<script>", item["raw_content_excerpt"])
        self.assertFalse(item["raw_content_truncated"])


class WebSearchDispatchTest(unittest.IsolatedAsyncioTestCase):
    async def test_duckduckgo_provider_dispatch(self) -> None:
        with patch("search_provider._fetch", return_value=(
            '<a class="result-link" href="https://ddg.example/9">九</a>'
        )), patch.dict("os.environ", {"SEARCH_PROVIDER": "duckduckgo"}):
            results = await web_search("什么")
        self.assertEqual(1, len(results))
        self.assertEqual("https://ddg.example/9", results[0]["url"])

    async def test_bing_provider_dispatch(self) -> None:
        with patch("search_provider._fetch", return_value=CANONICAL_BING), patch.dict(
            "os.environ", {"SEARCH_PROVIDER": "bing"}
        ):
            results = await web_search("CERN")
        self.assertEqual(2, len(results))

    async def test_fetch_raises_on_non_200(self) -> None:
        import httpx
        from unittest.mock import AsyncMock

        response = httpx.Response(
            503, text="service unavailable", request=httpx.Request("GET", "https://example.com")
        )
        with patch("httpx.AsyncClient.get", AsyncMock(return_value=response)):
            with self.assertRaises(SearchUnavailableError) as ctx:
                await _fetch("https://example.com")
        self.assertIn("HTTP 503", str(ctx.exception))


if __name__ == "__main__":
    unittest.main()
