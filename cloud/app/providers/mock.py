"""Deterministic fake provider for local runs and tests (no paid calls)."""

from __future__ import annotations

import asyncio

from app.providers.base import (
    ProviderBadOutput,
    ProviderError,
    ProviderRequest,
    ProviderResult,
    ProviderTimeout,
    Usage,
)


class MockProvider:
    """Modes: ok | fail | timeout | partial (drops every 2nd id) | slow | bad_output."""

    def __init__(self, model: str = "mock", mode: str = "ok", slow_seconds: float = 0.0) -> None:
        self.model = model
        self.mode = mode
        self.slow_seconds = slow_seconds
        self.calls = 0
        self.requests: list[ProviderRequest] = []

    async def translate(self, request: ProviderRequest) -> ProviderResult:
        self.calls += 1
        self.requests.append(request)
        chars = sum(len(t) for t in request.items.values())
        usage = Usage(
            prompt_tokens=max(1, chars // 3),
            completion_tokens=max(1, chars // 3),
            cache_miss_tokens=max(1, chars // 3),
        )
        if self.mode == "fail":
            raise ProviderError("mock failure")
        if self.mode == "timeout":
            raise ProviderTimeout("mock timeout")
        if self.mode == "bad_output":
            raise ProviderBadOutput("mock bad output", usage)
        if self.mode == "slow":
            await asyncio.sleep(self.slow_seconds)

        translations = {i: f"[{request.target_locale}] {t}" for i, t in request.items.items()}
        if self.mode == "partial":
            ids = list(request.items)
            translations = {i: translations[i] for n, i in enumerate(ids) if n % 2 == 0}
        return ProviderResult(
            translations=translations,
            usage=usage,
            model=self.model,
            finish_reason="stop",
            latency_ms=1,
        )
