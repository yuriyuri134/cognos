"""search_provider 离线测试：不访问网络，用既有 HTML 结构验证解析与格式化。"""

from __future__ import annotations

import asyncio
import unittest
from unittest.mock import patch

from search_provider import (
    SearchUnavailableError,
    _fetch,
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
