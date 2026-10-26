"""One translation request end to end: reserve -> provider -> settle.

Order of operations is what makes the money safe:

1. reserve quota in its own short transaction (InsufficientQuota here means
   DeepSeek is never called for an unpaid request);
2. call the provider with NO transaction or row lock held, under one overall
   deadline that is shorter than the reservation TTL;
3. settle in a second short transaction, fenced by the attempt number:
   charge only the characters that actually came back translated, return
   the rest.

A crash between 1 and 3 leaves a pending reservation; the sweeper releases
it after ``reservation_ttl``, so the client is never charged for it. If the
sweeper got there first, the result is withheld (504), not given away.
"""

from __future__ import annotations

import asyncio
import hashlib
import json
import logging
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Callable

from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker

from app import quota
from app.models import UsageLog
from app.providers.base import (
    ProviderError,
    ProviderRequest,
    ProviderResult,
    ProviderTimeout,
    TranslationProvider,
    Usage,
)

log = logging.getLogger("lingocanvas.translate")

# Upper bound used only for cost admission / worst-case booking, never billing:
# measured 0.18-0.40 tokens per character, 0.6 leaves a margin.
_PROMPT_TOKENS_PER_CHAR = 0.6
_PROMPT_OVERHEAD_TOKENS = 400


class SpendLimitReached(Exception):
    """Daily DeepSeek budget of the whole service is used up (kill switch)."""

    code = "service_budget_exhausted"


class PricingLike:  # structural type, see app/pricing.py
    def cost_micro_usd(self, usage: Usage, at: datetime) -> int: ...

    def worst_case_micro_usd(self, prompt_tokens: int, max_output_tokens: int) -> int: ...


@dataclass(frozen=True)
class ServiceConfig:
    limits: quota.Limits
    daily_spend_limit_micro_usd: int
    max_output_tokens: int
    # Overall budget for all provider calls of one request (incl. the follow-up).
    # Must stay well below limits.reservation_ttl (checked in __init__).
    call_deadline_seconds: float = 150.0


@dataclass(frozen=True)
class SiteIdentity:
    site_id: int
    account_id: int


@dataclass(frozen=True)
class TranslateRequest:
    request_id: str
    source_locale: str
    target_locale: str
    target_label: str
    items: dict[str, str]  # client key (e.g. 64-hex hash) -> source text
    glossary: dict[str, str]


@dataclass(frozen=True)
class TranslateResult:
    request_id: str
    translations: dict[str, str]  # client key -> translation
    missing: list[str]  # client keys without a translation (not charged)
    billed_chars: int
    replay: bool
    quota: quota.QuotaSummary


def count_chars(text: str) -> int:
    """Billing unit: Unicode code points of the source text exactly as sent."""
    return len(text)


def request_fingerprint(req: TranslateRequest) -> bytes:
    canonical = json.dumps(
        {
            "s": req.source_locale,
            "t": req.target_locale,
            "l": req.target_label,
            "i": sorted(req.items.items()),
            "g": sorted(req.glossary.items()),
        },
        ensure_ascii=False,
        separators=(",", ":"),
    )
    return hashlib.sha256(canonical.encode("utf-8")).digest()


def _utcnow() -> datetime:
    return datetime.now(timezone.utc)


class TranslationService:
    def __init__(
        self,
        sessionmaker: async_sessionmaker[AsyncSession],
        provider: TranslationProvider,
        pricing: PricingLike,
        config: ServiceConfig,
        clock: Callable[[], datetime] = _utcnow,
    ) -> None:
        # Settlement after the deadline needs headroom before the sweeper may act.
        if config.call_deadline_seconds + 30 > config.limits.reservation_ttl.total_seconds():
            raise ValueError("call_deadline_seconds must be at least 30s below reservation_ttl")
        self._sm = sessionmaker
        self._provider = provider
        self._pricing = pricing
        self._cfg = config
        self._clock = clock

    async def translate(self, site: SiteIdentity, req: TranslateRequest) -> TranslateResult:
        # Identical texts are sent and charged once (the plugin dedupes too).
        id_to_text: dict[str, str] = {}
        text_to_keys: dict[str, list[str]] = {}
        for key, text in req.items.items():
            if text not in text_to_keys:
                text_to_keys[text] = []
                id_to_text[str(len(id_to_text) + 1)] = text
            text_to_keys[text].append(key)
        chars = sum(count_chars(t) for t in id_to_text.values())

        # Worst case of the first call plus the follow-up, glossary included.
        await self._check_spend_limit(2 * self._worst_case_micro_usd(req, id_to_text, chars))

        now = self._clock()
        async with self._sm() as s, s.begin():
            outcome = await quota.reserve(
                s,
                account_id=site.account_id,
                site_id=site.site_id,
                request_id=req.request_id,
                request_hash=request_fingerprint(req),
                chars=chars,
                now=now,
                limits=self._cfg.limits,
            )

        replay = outcome.kind == "replay"
        try:
            try:
                async with asyncio.timeout(self._cfg.call_deadline_seconds):
                    translated = await self._call_with_followup(site, req, outcome.reservation_id, id_to_text, chars)
            except TimeoutError as exc:
                raise ProviderTimeout("overall provider deadline exceeded") from exc
        except BaseException:
            # Provider failure, deadline, cancellation or a bug: give the
            # characters back now (fenced by attempt), not after the TTL.
            if replay:
                await self._finish_replay(site, outcome)
            else:
                await self._settle(site, outcome, 0)
            raise

        billed = 0
        if replay:
            await self._finish_replay(site, outcome)
        else:
            wanted = sum(count_chars(id_to_text[i]) for i in translated)
            settled = await self._settle(site, outcome, wanted)
            if not settled.settled:
                # The sweeper released this attempt (or a newer attempt owns the
                # row): nothing was charged, so the result is not delivered.
                log.warning("settle lost site=%s request=%s", site.site_id, req.request_id)
                raise ProviderTimeout("reservation expired before settlement")
            billed = settled.billed

        translations: dict[str, str] = {}
        for short_id, out in translated.items():
            for key in text_to_keys[id_to_text[short_id]]:
                translations[key] = out
        missing = [k for k in req.items if k not in translations]

        async with self._sm() as s:
            remaining = await quota.summary(s, site.account_id, self._clock())

        log.info(
            "translate site=%s request=%s chars=%s billed=%s missing=%s replay=%s",
            site.site_id, req.request_id, chars, billed, len(missing), replay,
        )
        return TranslateResult(req.request_id, translations, missing, billed, replay, remaining)

    async def _call_with_followup(
        self,
        site: SiteIdentity,
        req: TranslateRequest,
        reservation_id: int,
        id_to_text: dict[str, str],
        chars: int,
    ) -> dict[str, str]:
        """First call for everything, then one call for whatever came back missing.

        The follow-up costs the service, not the client, and is skipped when
        the first call returned nothing (likely a systematic failure).
        """
        result = await self._call(site, req, reservation_id, id_to_text, chars)
        translated = dict(result.translations)
        missing = {i: t for i, t in id_to_text.items() if i not in translated}
        if missing and translated:
            try:
                retry_chars = sum(count_chars(t) for t in missing.values())
                again = await self._call(site, req, reservation_id, missing, retry_chars)
                translated.update({i: t for i, t in again.translations.items() if i in missing})
            except ProviderError:
                pass  # keep the first, partial result; missing items are not charged
        return translated

    async def _call(
        self,
        site: SiteIdentity,
        req: TranslateRequest,
        reservation_id: int,
        items: dict[str, str],
        chars: int,
    ) -> ProviderResult:
        max_tokens = self._max_tokens(items, chars)
        preq = ProviderRequest(
            source_locale=req.source_locale,
            target_locale=req.target_locale,
            target_label=req.target_label,
            items=items,
            glossary=req.glossary,
            max_tokens=max_tokens,
        )
        started = self._clock()
        worst = self._pricing.worst_case_micro_usd(self._prompt_tokens_estimate(req, chars), max_tokens)
        try:
            result = await self._provider.translate(preq)
        except ProviderError as exc:
            if exc.usage is not None:
                cost = self._pricing.cost_micro_usd(exc.usage, started)
            else:
                cost = worst if exc.maybe_billed else 0
            await self._log_usage_safely(site, req, reservation_id, items, chars, 0, exc.usage or Usage(),
                                         exc.outcome, started, 0, cost)
            raise
        except BaseException:
            # Deadline / cancellation while the request was in flight: it may
            # have been billed upstream, so book the worst case.
            await self._log_usage_safely(site, req, reservation_id, items, chars, 0, Usage(),
                                         "timeout", started, 0, worst)
            raise
        outcome = "ok" if len(result.translations) == len(items) else "partial"
        returned = sum(count_chars(items[i]) for i in result.translations if i in items)
        await self._log_usage_safely(site, req, reservation_id, items, chars, returned, result.usage, outcome,
                                     started, result.latency_ms, self._pricing.cost_micro_usd(result.usage, started),
                                     result.model)
        return result

    def _max_tokens(self, items: dict[str, str], chars: int) -> int:
        # Generous enough for Cyrillic escaped as \uXXXX plus keys; capped by config.
        estimate = int(chars * 1.5) + 16 * len(items) + 256
        return max(256, min(estimate, self._cfg.max_output_tokens))

    def _prompt_tokens_estimate(self, req: TranslateRequest, chars: int) -> int:
        glossary_chars = sum(len(k) + len(v) for k, v in req.glossary.items())
        return int((chars + glossary_chars) * _PROMPT_TOKENS_PER_CHAR) + _PROMPT_OVERHEAD_TOKENS

    def _worst_case_micro_usd(self, req: TranslateRequest, id_to_text: dict[str, str], chars: int) -> int:
        return self._pricing.worst_case_micro_usd(
            self._prompt_tokens_estimate(req, chars), self._max_tokens(id_to_text, chars)
        )

    async def _settle(self, site: SiteIdentity, outcome: quota.ReserveOutcome, billed: int) -> quota.SettleOutcome:
        async with self._sm() as s, s.begin():
            return await quota.settle(
                s,
                account_id=site.account_id,
                reservation_id=outcome.reservation_id,
                attempt=outcome.attempt,
                billed_chars=billed,
                now=self._clock(),
            )

    async def _finish_replay(self, site: SiteIdentity, outcome: quota.ReserveOutcome) -> None:
        async with self._sm() as s, s.begin():
            await quota.finish_replay(
                s, account_id=site.account_id, reservation_id=outcome.reservation_id, attempt=outcome.attempt
            )

    async def _check_spend_limit(self, upcoming_micro_usd: int) -> None:
        now = self._clock()
        midnight = now.replace(hour=0, minute=0, second=0, microsecond=0)
        async with self._sm() as s:
            spent = (
                await s.execute(
                    select(func.coalesce(func.sum(UsageLog.cost_micro_usd), 0)).where(UsageLog.created_at >= midnight)
                )
            ).scalar_one()
        # Admission counts this request's worst case, and failures with unknown
        # usage are booked at their worst case, so the cap holds up to the
        # requests already in flight (bounded by the in-flight limits).
        if spent + upcoming_micro_usd > self._cfg.daily_spend_limit_micro_usd:
            raise SpendLimitReached("daily provider budget reached")

    async def _log_usage_safely(self, *args, **kwargs) -> None:
        # Logging must never mask the real outcome (e.g. during cancellation).
        try:
            await self._log_usage(*args, **kwargs)
        except Exception:
            log.exception("usage_log write failed")

    async def _log_usage(
        self,
        site: SiteIdentity,
        req: TranslateRequest,
        reservation_id: int,
        items: dict[str, str],
        chars: int,
        returned_chars: int,
        usage: Usage,
        outcome: str,
        started: datetime,
        latency_ms: int,
        cost_micro_usd: int,
        model: str | None = None,
    ) -> None:
        async with self._sm() as s, s.begin():
            s.add(
                UsageLog(
                    account_id=site.account_id,
                    site_id=site.site_id,
                    reservation_id=reservation_id,
                    source_locale=req.source_locale,
                    target_locale=req.target_locale,
                    items=len(items),
                    chars=chars,
                    returned_chars=returned_chars,
                    model=model or self._provider.model,
                    prompt_tokens=usage.prompt_tokens,
                    completion_tokens=usage.completion_tokens,
                    cache_hit_tokens=usage.cache_hit_tokens,
                    reasoning_tokens=usage.reasoning_tokens,
                    cost_micro_usd=cost_micro_usd,
                    latency_ms=latency_ms,
                    outcome=outcome,
                    created_at=started,
                )
            )
