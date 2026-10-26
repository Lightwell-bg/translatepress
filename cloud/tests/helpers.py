"""Shared test helpers: request bodies, a configurable provider stub, quota shortcuts."""

from __future__ import annotations

import asyncio
import itertools
from datetime import datetime, timedelta, timezone

from app import quota
from app.providers.base import ProviderRequest, ProviderResult, Usage

_counter = itertools.count(1)


def new_rid(prefix: str = "req") -> str:
    """A fresh valid request id (8-64 chars of [A-Za-z0-9_-])."""
    return f"{prefix}-{next(_counter):08d}"


def body(items: dict[str, str] | None = None, **over) -> dict:
    data = {
        "request_id": new_rid(),
        "source_locale": "en",
        "target_locale": "de",
        "items": items if items is not None else {"k1": "Hello", "k2": "World"},
    }
    data.update(over)
    return data


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


class StubProvider:
    """Provider with scripted behaviour.

    omit: short ids that are never returned (permanently, also on the follow-up call);
    error: exception instance raised on every call (or the first ``fail_times`` calls);
    delay: seconds to sleep inside the call; on_call: async callback(request).
    """

    model = "stub"

    def __init__(self, *, omit=(), error=None, fail_times: int | None = None, delay: float = 0.0, on_call=None):
        self.omit = set(omit)
        self.error = error
        self.fail_times = fail_times
        self.delay = delay
        self.on_call = on_call
        self.calls = 0
        self.requests: list[ProviderRequest] = []

    async def translate(self, request: ProviderRequest) -> ProviderResult:
        self.calls += 1
        self.requests.append(request)
        if self.on_call is not None:
            await self.on_call(request)
        if self.delay:
            await asyncio.sleep(self.delay)
        if self.error is not None and (self.fail_times is None or self.calls <= self.fail_times):
            raise self.error
        translations = {i: f"[{request.target_locale}] {t}" for i, t in request.items.items() if i not in self.omit}
        return ProviderResult(
            translations=translations,
            usage=Usage(prompt_tokens=10, completion_tokens=10, cache_miss_tokens=10),
            model=self.model,
            finish_reason="stop",
            latency_ms=1,
        )


class GatedProvider(StubProvider):
    """StubProvider whose listed calls (1-based) block until ``release(n)`` is called.

    Deterministic replacement for sleeps: ``await p.entered(n)`` returns once call
    ``n`` is inside the provider (so the reservation exists and the request is in flight).
    """

    def __init__(self, gated=(), **kw):
        super().__init__(**kw)
        self.gated = set(gated)
        self._entered: dict[int, asyncio.Event] = {}
        self._open: dict[int, asyncio.Event] = {}
        self.on_call = self._gate

    def _event(self, table: dict[int, asyncio.Event], n: int) -> asyncio.Event:
        return table.setdefault(n, asyncio.Event())

    async def _gate(self, request: ProviderRequest) -> None:
        n = self.calls  # no await between the increment and this line
        self._event(self._entered, n).set()
        if n in self.gated:
            await self._event(self._open, n).wait()

    async def entered(self, n: int, timeout: float = 10.0) -> None:
        await asyncio.wait_for(self._event(self._entered, n).wait(), timeout)

    def release(self, n: int) -> None:
        self._event(self._open, n).set()


LIMITS = quota.Limits(
    reservation_ttl=timedelta(seconds=300),
    per_site_requests_per_minute=1000,
    max_in_flight_per_account=1000,
    max_replays=2,
    max_attempts=3,
)


async def reserve(sm, account_id: int, site_id: int, rid: str, chars: int, *, now=None, limits=LIMITS, digest=b"h" * 32):
    async with sm() as s, s.begin():
        return await quota.reserve(
            s, account_id=account_id, site_id=site_id, request_id=rid, request_hash=digest,
            chars=chars, now=now or utcnow(), limits=limits,
        )


async def settle(sm, account_id: int, reservation_id: int, billed: int, *, attempt: int = 1, now=None):
    async with sm() as s, s.begin():
        return await quota.settle(
            s, account_id=account_id, reservation_id=reservation_id, attempt=attempt,
            billed_chars=billed, now=now or utcnow(),
        )


async def grant_raw(sm, account_id: int, kind: str, chars: int, *, valid_from=None, expires_at=None, ref=None):
    now = utcnow()
    async with sm() as s, s.begin():
        bucket_id, _ = await quota.grant(
            s, account_id=account_id, kind=kind, chars=chars, now=now, source_ref=ref,
            valid_from=valid_from or now - timedelta(minutes=1),
            expires_at=expires_at if expires_at is not None else now + timedelta(days=30),
        )
        return bucket_id
