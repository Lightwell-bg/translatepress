"""Admin CLI: ``python -m app.cli <command>``. Never prints client text."""

from __future__ import annotations

import argparse
import asyncio
import calendar
import logging
import sys
from datetime import datetime, timedelta, timezone

from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker

from app import quota
from app.config import Settings
from app.db import make_engine, make_sessionmaker
from app.models import Account, LedgerEntry, QuotaBucket, Reservation, Site, UsageLog
from app.security import issue_token
from app.sweeper import sweep_once

log = logging.getLogger("lingocanvas.cli")

SM = async_sessionmaker[AsyncSession]


def add_months(value: datetime, months: int) -> datetime:
    """Calendar-month arithmetic; the day is clamped to the target month length."""
    index = value.month - 1 + months
    year, month = value.year + index // 12, index % 12 + 1
    day = min(value.day, calendar.monthrange(year, month)[1])
    return value.replace(year=year, month=month, day=day)


def _now() -> datetime:
    return datetime.now(timezone.utc)


def _parse_start(raw: str | None) -> datetime:
    if not raw:
        # Midnight UTC today, so repeating the command the same day is idempotent.
        return _now().replace(hour=0, minute=0, second=0, microsecond=0)
    value = datetime.fromisoformat(raw)
    return value if value.tzinfo else value.replace(tzinfo=timezone.utc)


async def create_account(sm: SM, settings: Settings, args: argparse.Namespace) -> None:
    if args.plan and args.plan not in settings.cfg.plans:
        raise SystemExit(f"unknown plan {args.plan!r}")
    async with sm() as s, s.begin():
        acc = Account(name=args.name, email=args.email, plan=args.plan)
        s.add(acc)
        await s.flush()
        print(f"account id={acc.id} name={acc.name!r} plan={acc.plan}")


async def add_site(sm: SM, settings: Settings, args: argparse.Namespace) -> None:
    async with sm() as s, s.begin():
        acc = (
            await s.execute(select(Account).where(Account.id == args.account).with_for_update())
        ).scalar_one_or_none()
        if acc is None:
            raise SystemExit("account not found")
        plan = settings.cfg.plans.get(acc.plan) if acc.plan else None
        if plan is None:
            raise SystemExit("account has no plan: set a plan before adding sites")
        used = (
            await s.execute(
                select(func.count()).select_from(Site).where(Site.account_id == acc.id, Site.revoked_at.is_(None))
            )
        ).scalar_one()
        if used >= plan.max_sites:
            raise SystemExit(f"plan {acc.plan} allows {plan.max_sites} site(s); {used} already connected")
        token, prefix, digest = issue_token(settings.token_pepper.get_secret_value())
        site = Site(account_id=acc.id, name=args.name, token_prefix=prefix, token_hash=digest)
        s.add(site)
        await s.flush()
        site_id = site.id
    print(f"site id={site_id}")
    print("TOKEN (shown once, it is NOT stored - copy it now):")
    print(token)


async def list_sites(sm: SM, settings: Settings, args: argparse.Namespace) -> None:
    async with sm() as s:
        rows = (await s.execute(select(Site).where(Site.account_id == args.account).order_by(Site.id))).scalars()
        for site in rows:
            state = "revoked" if site.revoked_at else "active"
            print(f"site id={site.id} name={site.name!r} prefix={site.token_prefix} {state} last_used={site.last_used_at}")


async def revoke_site(sm: SM, settings: Settings, args: argparse.Namespace) -> None:
    async with sm() as s, s.begin():
        site = (await s.execute(select(Site).where(Site.id == args.site).with_for_update())).scalar_one_or_none()
        if site is None:
            raise SystemExit("site not found")
        if site.revoked_at is None:
            site.revoked_at = _now()
    print("revoked")


async def grant_month(sm: SM, settings: Settings, args: argparse.Namespace) -> None:
    async with sm() as s, s.begin():
        acc = (await s.execute(select(Account).where(Account.id == args.account))).scalar_one_or_none()
        if acc is None:
            raise SystemExit("account not found")
        plan = settings.cfg.plans.get(acc.plan) if acc.plan else None
        if plan is None:
            raise SystemExit("account has no plan")
        start = _parse_start(args.start)
        try:
            bucket_id, created = await quota.grant(
                s,
                account_id=acc.id,
                kind="monthly",
                chars=plan.chars_per_month,
                now=_now(),
                source="manual",
                source_ref=f"manual:month:{acc.id}:{start.isoformat()}",
                valid_from=start,
                expires_at=add_months(start, 1),
            )
        except quota.GrantConflict as exc:
            raise SystemExit(f"error: {exc}") from exc
    print(f"bucket id={bucket_id} {'created' if created else 'already existed'}")


async def grant_pack(sm: SM, settings: Settings, args: argparse.Namespace) -> None:
    chars = settings.cfg.packs.get(args.pack)
    if chars is None:
        raise SystemExit(f"unknown pack {args.pack!r}")
    now = _now()
    async with sm() as s, s.begin():
        # A repeated command must be recognised as the same grant: reuse the
        # dates of the existing bucket (they depend on "now"), so only a real
        # difference (account, kind, amount) is reported as a conflict.
        expires_at, valid_from = add_months(now, 12), None
        if args.ref:
            prior = (
                await s.execute(select(QuotaBucket).where(QuotaBucket.source_ref == args.ref))
            ).scalar_one_or_none()
            if prior is not None:
                expires_at, valid_from = prior.expires_at, prior.valid_from
        try:
            bucket_id, created = await quota.grant(
                s,
                account_id=args.account,
                kind="pack",
                chars=chars,
                now=now,
                source="manual",
                source_ref=args.ref,
                valid_from=valid_from,
                expires_at=expires_at,
            )
        except quota.GrantConflict as exc:
            raise SystemExit(f"error: {exc}") from exc
    print(f"bucket id={bucket_id} {'created' if created else 'already existed'}")


async def revoke_bucket(sm: SM, settings: Settings, args: argparse.Namespace) -> None:
    async with sm() as s, s.begin():
        bucket = (await s.execute(select(QuotaBucket).where(QuotaBucket.id == args.bucket))).scalar_one_or_none()
        if bucket is None:
            raise SystemExit("bucket not found")
        taken = await quota.revoke_unused(
            s, account_id=bucket.account_id, bucket_id=bucket.id, chars=None, now=_now(), note="manual revoke"
        )
    print(f"revoked {taken} unused characters; bucket closed")


async def usage(sm: SM, settings: Settings, args: argparse.Namespace) -> None:
    since = _now() - timedelta(days=args.days)
    async with sm() as s:
        # What the customer was charged: committed reservations and the ledger.
        billed_requests, billed_chars = (
            await s.execute(
                select(func.count(), func.coalesce(func.sum(Reservation.billed_chars), 0)).where(
                    Reservation.account_id == args.account,
                    Reservation.status == "committed",
                    Reservation.finished_at >= since,
                )
            )
        ).one()
        ledger_commit = (
            await s.execute(
                select(func.coalesce(func.sum(LedgerEntry.chars), 0)).where(
                    LedgerEntry.account_id == args.account,
                    LedgerEntry.kind == "commit",
                    LedgerEntry.created_at >= since,
                )
            )
        ).scalar_one()
        # What the provider did for us: every call, including free replays/follow-ups.
        calls, chars, returned, prompt, completion, cost = (
            await s.execute(
                select(
                    func.count(),
                    func.coalesce(func.sum(UsageLog.chars), 0),
                    func.coalesce(func.sum(UsageLog.returned_chars), 0),
                    func.coalesce(func.sum(UsageLog.prompt_tokens), 0),
                    func.coalesce(func.sum(UsageLog.completion_tokens), 0),
                    func.coalesce(func.sum(UsageLog.cost_micro_usd), 0),
                ).where(UsageLog.account_id == args.account, UsageLog.created_at >= since)
            )
        ).one()
    print(f"last {args.days}d, account {args.account}")
    print(f"  customer billing: requests={billed_requests} billed_chars={billed_chars} ledger_commit_chars={ledger_commit}")
    print(
        f"  provider work:    calls={calls} sent_chars={chars} returned_chars={returned} "
        f"prompt_tokens={prompt} completion_tokens={completion} cost_usd={cost / 1_000_000:.6f}"
    )


async def spend(sm: SM, settings: Settings, args: argparse.Namespace) -> None:
    since = (_now() - timedelta(days=args.days - 1)).replace(hour=0, minute=0, second=0, microsecond=0)
    day = func.date_trunc("day", UsageLog.created_at)
    async with sm() as s:
        rows = (
            await s.execute(
                select(day, func.count(), func.sum(UsageLog.cost_micro_usd))
                .where(UsageLog.created_at >= since)
                .group_by(day)
                .order_by(day)
            )
        ).all()
    for d, n, cost in rows:
        print(f"{d:%Y-%m-%d} calls={n} cost_usd={(cost or 0) / 1_000_000:.6f}")
    print(f"total cost_usd={sum((r[2] or 0) for r in rows) / 1_000_000:.6f}")


async def sweep(sm: SM, settings: Settings, args: argparse.Namespace) -> None:
    print(f"released {await sweep_once(sm)} reservation(s)")


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog="python -m app.cli")
    sub = p.add_subparsers(dest="cmd", required=True)

    c = sub.add_parser("create-account")
    c.add_argument("--name", required=True)
    c.add_argument("--email")
    c.add_argument("--plan", choices=["starter", "pro", "business"])
    c.set_defaults(func=create_account)

    c = sub.add_parser("add-site")
    c.add_argument("--account", type=int, required=True)
    c.add_argument("--name", required=True)
    c.set_defaults(func=add_site)

    c = sub.add_parser("list-sites")
    c.add_argument("--account", type=int, required=True)
    c.set_defaults(func=list_sites)

    c = sub.add_parser("revoke-site")
    c.add_argument("--site", type=int, required=True)
    c.set_defaults(func=revoke_site)

    c = sub.add_parser("grant-month")
    c.add_argument("--account", type=int, required=True)
    c.add_argument("--start", help="ISO date/time, default: today 00:00 UTC")
    c.set_defaults(func=grant_month)

    c = sub.add_parser("grant-pack")
    c.add_argument("--account", type=int, required=True)
    c.add_argument("--pack", required=True, choices=["pack_250k", "pack_1m"])
    c.add_argument("--ref", help="idempotency key, e.g. a payment id")
    c.set_defaults(func=grant_pack)

    c = sub.add_parser("revoke-bucket")
    c.add_argument("--bucket", type=int, required=True)
    c.set_defaults(func=revoke_bucket)

    c = sub.add_parser("usage")
    c.add_argument("--account", type=int, required=True)
    c.add_argument("--days", type=int, default=30)
    c.set_defaults(func=usage)

    c = sub.add_parser("spend")
    c.add_argument("--days", type=int, default=7)
    c.set_defaults(func=spend)

    c = sub.add_parser("sweep")
    c.set_defaults(func=sweep)
    return p


async def _run(args: argparse.Namespace) -> None:
    settings = Settings()  # type: ignore[call-arg]
    engine = make_engine(settings)
    try:
        await args.func(make_sessionmaker(engine), settings, args)
    finally:
        await engine.dispose()


def main(argv: list[str] | None = None) -> int:
    logging.basicConfig(level=logging.WARNING)
    args = build_parser().parse_args(argv)
    asyncio.run(_run(args))
    return 0


if __name__ == "__main__":
    sys.exit(main())
