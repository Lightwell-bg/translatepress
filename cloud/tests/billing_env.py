"""BillingEnv: app + fake gateway + shortcuts for the billing flows used by many tests."""

from __future__ import annotations

import hashlib
import secrets
from datetime import timedelta

from sqlalchemy import text

from tests import billing_fakes as bf
from tests.conftest import auth
from tests.billing_fakes import utcnow  # whole seconds: Stripe timestamps carry no microseconds
from tests.helpers import body


class BillingEnv:
    def __init__(self, client, app, gw: bf.FakeGateway, sessionmaker, make_site) -> None:
        self.client = client
        self.app = app
        self.gw = gw
        self.sm = sessionmaker
        self.make_site = make_site

    # --- db ---
    async def q(self, sql: str, **params):
        async with self.sm() as s:
            return [dict(r._mapping) for r in await s.execute(text(sql), params)]

    async def one(self, sql: str, **params):
        rows = await self.q(sql, **params)
        assert len(rows) == 1, rows
        return rows[0]

    async def scalar(self, sql: str, **params):
        return (await self.one(sql, **params))["v"]

    async def execute(self, sql: str, **params) -> None:
        async with self.sm() as s, s.begin():
            await s.execute(text(sql), params)

    async def counts(self, *tables: str) -> dict[str, int]:
        return {t: await self.scalar(f"SELECT count(*) AS v FROM {t}") for t in tables}

    # --- webhook ---
    async def send(self, evt: dict, **kw):
        return await bf.post_event(self.client, evt, **kw)

    async def send_ok(self, evt: dict, expect: str = "processed") -> None:
        r = await self.send(evt)
        assert r.status_code == 200, r.text
        assert r.json() == {"status": expect}, r.json()

    # --- flows ---
    async def signup(self, email: str | None = None, headers: dict | None = None) -> tuple[str, str, str]:
        """POST /v1/billing/signup. Returns (claim_id, claim_secret, checkout session id)."""
        claim_secret = secrets.token_urlsafe(24)
        payload = {
            "plan": "starter",
            "site_name": "Мой сайт example.com",
            "claim_secret_sha256": hashlib.sha256(claim_secret.encode()).hexdigest(),
        }
        if email:
            payload["email"] = email
        r = await self.client.post("/v1/billing/signup", json=payload, headers=headers or {})
        assert r.status_code == 200, r.text
        claim_id = r.json()["claim_id"]
        session_id = await self.scalar(
            "SELECT stripe_checkout_session_id AS v FROM checkout_claims WHERE id = :i", i=claim_id
        )
        return claim_id, claim_secret, session_id

    async def claim(self, claim_id: str, claim_secret: str):
        return await self.client.post("/v1/billing/claim", json={"claim_id": claim_id, "claim_secret": claim_secret})

    async def first_payment(self, *, order: str = "checkout_first", shape: str = "old", email: str | None = None) -> dict:
        """Sign up and deliver the payment events in the given order. Does NOT pick up the token."""
        claim_id, claim_secret, session_id = await self.signup(email)
        start = utcnow() - timedelta(days=1)
        end = start + timedelta(days=30)
        meta = {"lc_app": "lingocanvas", "lc_claim_id": claim_id}
        self.gw.set_subscription(bf.sub_info(start=start, end=end, metadata=meta))
        checkout = bf.event(
            "checkout.session.completed",
            bf.checkout_session(id=session_id, metadata=meta, email=email),
        )
        paid = bf.event(
            "invoice.paid",
            bf.invoice(start=start, end=end, metadata=meta, shape=shape),
        )
        if shape == "new":
            self.gw.invoice_payment_intents["in_1"] = "pi_1"
        first, second = (checkout, paid) if order == "checkout_first" else (paid, checkout)
        await self.send_ok(first)
        await self.send_ok(second)
        return {
            "claim_id": claim_id, "claim_secret": claim_secret, "start": start, "end": end,
            "checkout_event": checkout, "invoice_event": paid, "session_id": session_id,
        }

    async def paid_account(self, **kw) -> dict:
        """first_payment + token pickup. Adds account_id, site_id, token."""
        info = await self.first_payment(**kw)
        r = await self.claim(info["claim_id"], info["claim_secret"])
        assert r.status_code == 200, r.text
        info.update(r.json())
        return info

    async def translate(self, token: str, **over):
        return await self.client.post("/v1/translate", json=body(**over), headers=auth(token))

    async def buckets(self, account_id: int):
        return await self.q(
            "SELECT id, kind, granted, used, reserved, revoke_pending, valid_from, expires_at, revoked_at, source_ref "
            "FROM quota_buckets WHERE account_id = :a ORDER BY id",
            a=account_id,
        )

    def pack_session_event(self, account_id: int, *, id="cs_pack_1", pi="pi_pack_1", payment_status="paid",
                           type_="checkout.session.completed", created=None, amount=700):
        return bf.event(
            type_,
            bf.checkout_session(
                id=id, mode="payment", subscription=None, payment_intent=pi, payment_status=payment_status,
                amount_total=amount, created=created,
                metadata={"lc_app": "lingocanvas", "lc_account_id": str(account_id), "lc_price_id": bf.PRICE_PACK},
            ),
        )
