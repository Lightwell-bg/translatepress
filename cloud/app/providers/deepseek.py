"""DeepSeek chat-completions client (OpenAI-compatible API)."""

from __future__ import annotations

import asyncio
import logging
import time

import httpx

from app.providers.base import (
    ProviderBadOutput,
    ProviderRateLimited,
    ProviderRejected,
    ProviderRequest,
    ProviderResult,
    ProviderTimeout,
    ProviderUnavailable,
    Usage,
)
from app.providers.prompt import build_messages, parse_translations

log = logging.getLogger("lingocanvas.deepseek")

RETRY_DELAY_SECONDS = 1.0


def _int(value: object) -> int:
    return value if isinstance(value, int) and not isinstance(value, bool) else 0


def parse_usage(raw: object) -> Usage:
    if not isinstance(raw, dict):
        return Usage()
    prompt = _int(raw.get("prompt_tokens"))
    hit = _int(raw.get("prompt_cache_hit_tokens"))
    miss = raw.get("prompt_cache_miss_tokens")
    miss = _int(miss) if miss is not None else max(0, prompt - hit)
    details = raw.get("completion_tokens_details")
    reasoning = _int(details.get("reasoning_tokens")) if isinstance(details, dict) else 0
    return Usage(
        prompt_tokens=prompt,
        completion_tokens=_int(raw.get("completion_tokens")),
        cache_hit_tokens=hit,
        cache_miss_tokens=miss,
        reasoning_tokens=reasoning,
    )


class DeepSeekProvider:
    def __init__(
        self,
        *,
        api_key: str,
        base_url: str,
        model: str,
        timeout_seconds: float,
        transport: httpx.AsyncBaseTransport | None = None,
        retry_delay: float = RETRY_DELAY_SECONDS,
    ) -> None:
        self.model = model
        self._retry_delay = retry_delay
        self._client = httpx.AsyncClient(
            base_url=base_url,
            headers={"Authorization": f"Bearer {api_key}"},
            timeout=httpx.Timeout(timeout_seconds),
            transport=transport,
        )

    async def aclose(self) -> None:
        await self._client.aclose()

    async def translate(self, request: ProviderRequest) -> ProviderResult:
        body = {
            "model": self.model,
            "messages": build_messages(request),
            "response_format": {"type": "json_object"},
            "thinking": {"type": "disabled"},
            "temperature": 0,
            "max_tokens": request.max_tokens,
            "stream": False,
        }
        started = time.monotonic()
        response = await self._post_with_retry(body)
        latency_ms = int((time.monotonic() - started) * 1000)

        try:
            data = response.json()
        except ValueError as exc:
            raise ProviderBadOutput("response is not JSON") from exc
        # Tokens were consumed whenever the response says so, even if the
        # content is unusable: keep the usage so the cost is booked exactly.
        usage = parse_usage(data.get("usage")) if isinstance(data, dict) and "usage" in data else None
        try:
            choice = data["choices"][0]
            content = choice["message"]["content"]
            finish_reason = choice.get("finish_reason")
        except (KeyError, IndexError, TypeError, AttributeError) as exc:
            raise ProviderBadOutput("unexpected response shape", usage) from exc
        if content is not None and not isinstance(content, str):
            raise ProviderBadOutput("unexpected content type", usage)
        translations = parse_translations(content, request.items, usage)
        usage = usage or Usage()
        return ProviderResult(
            translations=translations,
            usage=usage,
            model=str(data.get("model") or self.model),
            finish_reason=finish_reason if isinstance(finish_reason, str) else None,
            latency_ms=latency_ms,
        )

    async def _post_with_retry(self, body: dict) -> httpx.Response:
        """POST once; retry ONCE only if the failure proves nothing was sent.

        A paid request must never be repeated when it may already be running
        upstream: after the request is on the wire (read/write timeouts, 5xx,
        broken connection) there is no retry and the cost is booked as
        "maybe billed" by the service.
        """
        for attempt in (1, 2):
            try:
                response = await self._client.post("/chat/completions", json=body)
            except (httpx.ConnectError, httpx.ConnectTimeout, httpx.PoolTimeout) as exc:
                if attempt == 1:
                    log.warning("deepseek connect-phase failure, retrying once")
                    await asyncio.sleep(self._retry_delay)
                    continue
                log.warning("deepseek unreachable")
                raise ProviderUnavailable("provider unreachable", maybe_billed=False) from exc
            except httpx.TimeoutException as exc:
                # Read/write timeout: the provider may still process (and bill) it.
                log.warning("deepseek timeout")
                raise ProviderTimeout("provider timeout") from exc
            except httpx.TransportError as exc:
                log.warning("deepseek transport error after send")
                raise ProviderUnavailable("provider connection failed", maybe_billed=True) from exc

            status = response.status_code
            if status == 429:
                log.warning("deepseek rate limited")
                raise ProviderRateLimited("provider rate limited")
            if status >= 500:
                log.warning("deepseek status=%s", status)
                raise ProviderUnavailable("provider unavailable", maybe_billed=True)
            if status >= 400:
                log.error("deepseek rejected the request status=%s", status)
                raise ProviderRejected("provider rejected the request")
            return response
        raise ProviderUnavailable("provider unavailable")  # pragma: no cover
