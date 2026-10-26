"""Releases reservations stuck in 'pending' (crashed or very slow requests)."""

from __future__ import annotations

import asyncio
import logging
from datetime import datetime, timezone

from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker

from app import quota
from app.billing import events

log = logging.getLogger("lingocanvas.sweeper")


async def sweep_once(sessionmaker: async_sessionmaker[AsyncSession], now: datetime | None = None) -> int:
    """Release expired pending reservations, one transaction each. Returns how many."""
    now = now or datetime.now(timezone.utc)
    async with sessionmaker() as s:
        pending = await quota.expired_pending_ids(s, now)
    released = 0
    for account_id, reservation_id in pending:
        try:
            async with sessionmaker() as s, s.begin():
                if await quota.release_if_expired(
                    s, account_id=account_id, reservation_id=reservation_id, now=now
                ):
                    released += 1
        except Exception:
            log.exception("sweeper failed for reservation %s", reservation_id)
    if released:
        log.info("sweeper released %s reservation(s)", released)
    return released


async def prune_once(
    sessionmaker: async_sessionmaker[AsyncSession], retention_days: int, now: datetime | None = None
) -> int:
    """Delete parked refund/dispute facts that never matched a payment (own transaction)."""
    now = now or datetime.now(timezone.utc)
    async with sessionmaker() as s, s.begin():
        return await events.prune_parked(s, now, retention_days)


async def sweeper_loop(
    sessionmaker: async_sessionmaker[AsyncSession],
    interval_seconds: float,
    unmatched_retention_days: int | None = None,
) -> None:
    while True:
        try:
            await sweep_once(sessionmaker)
        except asyncio.CancelledError:
            raise
        except Exception:
            log.exception("sweeper iteration failed")
        if unmatched_retention_days is not None:
            try:
                await prune_once(sessionmaker, unmatched_retention_days)
            except asyncio.CancelledError:
                raise
            except Exception:
                log.exception("pruning of parked charge events failed")
        await asyncio.sleep(interval_seconds)
