"""Expose only the Talk webhook path to a public HTTPS tunnel.

The full Cognos web app must remain private. This proxy deliberately forwards
one fixed POST path and does not expose bootstrap, frontend, or other APIs.
"""

from __future__ import annotations

import argparse

import httpx
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
import uvicorn

WEBHOOK_PATH = "/api/integrations/nextcloud-talk/webhook"


def create_app(upstream: str) -> FastAPI:
    app = FastAPI(title="Cognos Talk webhook proxy", docs_url=None, redoc_url=None)
    upstream_url = upstream.rstrip("/") + WEBHOOK_PATH

    @app.get("/_health")
    async def health() -> dict[str, str]:
        return {"status": "ready"}

    @app.api_route("/{path:path}", methods=["GET", "PUT", "PATCH", "DELETE", "OPTIONS"])
    async def reject_non_post(path: str) -> JSONResponse:
        return JSONResponse({"detail": "Not found"}, status_code=404)

    @app.post(WEBHOOK_PATH)
    async def forward(request: Request) -> JSONResponse:
        body = await request.body()
        if len(body) > 512 * 1024:
            return JSONResponse({"detail": "Payload too large"}, status_code=413)
        headers = {
            key: value
            for key, value in request.headers.items()
            if key.lower() in {
                "content-type",
                "x-nextcloud-talk-random",
                "x-nextcloud-talk-signature",
                "x-nextcloud-talk-backend",
            }
        }
        try:
            async with httpx.AsyncClient(timeout=httpx.Timeout(35.0, connect=5.0)) as client:
                response = await client.post(upstream_url, content=body, headers=headers)
        except httpx.HTTPError:
            return JSONResponse({"detail": "Webhook upstream unavailable"}, status_code=502)
        return JSONResponse(response.json() if response.content else {}, status_code=response.status_code)

    return app


def main() -> None:
    parser = argparse.ArgumentParser(description="Private Cognos Talk webhook proxy")
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=8766)
    parser.add_argument("--upstream", default="http://127.0.0.1:8765")
    args = parser.parse_args()
    uvicorn.run(create_app(args.upstream), host=args.host, port=args.port, reload=False)


if __name__ == "__main__":
    main()
