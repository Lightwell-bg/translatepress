"""initial schema

Revision ID: 0001
Revises:
Create Date: 2026-10-26
"""
from alembic import op
import sqlalchemy as sa

revision = "0001"
down_revision = None
branch_labels = None
depends_on = None

NOW = sa.text("now()")
ZERO = sa.text("0")


def _created_at() -> sa.Column:
    return sa.Column("created_at", sa.DateTime(timezone=True), server_default=NOW, nullable=False)


def upgrade() -> None:
    op.create_table(
        "accounts",
        sa.Column("id", sa.BigInteger(), primary_key=True, autoincrement=True),
        sa.Column("name", sa.String(200), nullable=False),
        sa.Column("email", sa.String(320), nullable=True),
        sa.Column("status", sa.String(20), server_default=sa.text("'active'"), nullable=False),
        sa.Column("plan", sa.String(20), nullable=True),
        sa.Column("stripe_customer_id", sa.String(64), nullable=True),
        _created_at(),
        sa.CheckConstraint("status IN ('active','suspended')", name="ck_accounts_status"),
        sa.UniqueConstraint("stripe_customer_id"),
    )

    op.create_table(
        "sites",
        sa.Column("id", sa.BigInteger(), primary_key=True, autoincrement=True),
        sa.Column("account_id", sa.BigInteger(), sa.ForeignKey("accounts.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("name", sa.String(255), nullable=False),
        sa.Column("token_prefix", sa.String(16), nullable=False),
        sa.Column("token_hash", sa.LargeBinary(32), nullable=False),
        _created_at(),
        sa.Column("revoked_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("last_used_at", sa.DateTime(timezone=True), nullable=True),
        sa.UniqueConstraint("token_hash"),
    )
    op.create_index("ix_sites_account_id", "sites", ["account_id"])

    op.create_table(
        "quota_buckets",
        sa.Column("id", sa.BigInteger(), primary_key=True, autoincrement=True),
        sa.Column("account_id", sa.BigInteger(), sa.ForeignKey("accounts.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("kind", sa.String(10), nullable=False),
        sa.Column("source", sa.String(20), server_default=sa.text("'manual'"), nullable=False),
        sa.Column("source_ref", sa.String(128), nullable=True),
        sa.Column("granted", sa.BigInteger(), nullable=False),
        sa.Column("used", sa.BigInteger(), server_default=ZERO, nullable=False),
        sa.Column("reserved", sa.BigInteger(), server_default=ZERO, nullable=False),
        sa.Column("valid_from", sa.DateTime(timezone=True), server_default=NOW, nullable=False),
        sa.Column("expires_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("revoked_at", sa.DateTime(timezone=True), nullable=True),
        _created_at(),
        sa.CheckConstraint("kind IN ('monthly','pack')", name="ck_buckets_kind"),
        sa.CheckConstraint("granted >= 0 AND used >= 0 AND reserved >= 0", name="ck_buckets_non_negative"),
        sa.CheckConstraint("used + reserved <= granted", name="ck_buckets_not_overspent"),
        sa.UniqueConstraint("source_ref"),
    )
    op.create_index("ix_quota_buckets_account_id", "quota_buckets", ["account_id"])

    op.create_table(
        "reservations",
        sa.Column("id", sa.BigInteger(), primary_key=True, autoincrement=True),
        sa.Column("account_id", sa.BigInteger(), sa.ForeignKey("accounts.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("site_id", sa.BigInteger(), sa.ForeignKey("sites.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("request_id", sa.String(64), nullable=False),
        sa.Column("request_hash", sa.LargeBinary(32), nullable=False),
        sa.Column("chars", sa.BigInteger(), nullable=False),
        sa.Column("billed_chars", sa.BigInteger(), server_default=ZERO, nullable=False),
        sa.Column("status", sa.String(10), nullable=False),
        sa.Column("attempts", sa.Integer(), server_default=sa.text("1"), nullable=False),
        sa.Column("replays", sa.Integer(), server_default=ZERO, nullable=False),
        _created_at(),
        sa.Column("expires_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("finished_at", sa.DateTime(timezone=True), nullable=True),
        sa.UniqueConstraint("site_id", "request_id", name="uq_reservations_site_request"),
        sa.CheckConstraint("status IN ('pending','committed','released')", name="ck_reservations_status"),
        sa.CheckConstraint("chars >= 0 AND billed_chars >= 0 AND billed_chars <= chars", name="ck_reservations_chars"),
    )
    op.create_index("ix_reservations_account_id", "reservations", ["account_id"])
    op.create_index(
        "ix_reservations_pending_expiry",
        "reservations",
        ["expires_at"],
        postgresql_where=sa.text("status = 'pending'"),
    )
    op.create_index("ix_reservations_site_created", "reservations", ["site_id", "created_at"])

    op.create_table(
        "reservation_items",
        sa.Column(
            "reservation_id", sa.BigInteger(), sa.ForeignKey("reservations.id", ondelete="CASCADE"), primary_key=True
        ),
        sa.Column(
            "bucket_id", sa.BigInteger(), sa.ForeignKey("quota_buckets.id", ondelete="RESTRICT"), primary_key=True
        ),
        sa.Column("chars", sa.BigInteger(), nullable=False),
        sa.CheckConstraint("chars > 0", name="ck_reservation_items_positive"),
    )

    op.create_table(
        "ledger",
        sa.Column("id", sa.BigInteger(), primary_key=True, autoincrement=True),
        sa.Column("account_id", sa.BigInteger(), sa.ForeignKey("accounts.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("bucket_id", sa.BigInteger(), sa.ForeignKey("quota_buckets.id", ondelete="RESTRICT"), nullable=True),
        sa.Column(
            "reservation_id", sa.BigInteger(), sa.ForeignKey("reservations.id", ondelete="SET NULL"), nullable=True
        ),
        sa.Column("kind", sa.String(10), nullable=False),
        sa.Column("chars", sa.BigInteger(), nullable=False),
        sa.Column("note", sa.String(200), nullable=True),
        _created_at(),
    )
    op.create_index("ix_ledger_account_id", "ledger", ["account_id"])

    op.create_table(
        "usage_log",
        sa.Column("id", sa.BigInteger(), primary_key=True, autoincrement=True),
        sa.Column("account_id", sa.BigInteger(), sa.ForeignKey("accounts.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("site_id", sa.BigInteger(), sa.ForeignKey("sites.id", ondelete="RESTRICT"), nullable=False),
        sa.Column(
            "reservation_id", sa.BigInteger(), sa.ForeignKey("reservations.id", ondelete="SET NULL"), nullable=True
        ),
        sa.Column("source_locale", sa.String(16), nullable=False),
        sa.Column("target_locale", sa.String(16), nullable=False),
        sa.Column("items", sa.Integer(), nullable=False),
        sa.Column("chars", sa.BigInteger(), nullable=False),
        sa.Column("billed_chars", sa.BigInteger(), server_default=ZERO, nullable=False),
        sa.Column("model", sa.String(64), nullable=False),
        sa.Column("prompt_tokens", sa.Integer(), server_default=ZERO, nullable=False),
        sa.Column("completion_tokens", sa.Integer(), server_default=ZERO, nullable=False),
        sa.Column("cache_hit_tokens", sa.Integer(), server_default=ZERO, nullable=False),
        sa.Column("reasoning_tokens", sa.Integer(), server_default=ZERO, nullable=False),
        sa.Column("cost_micro_usd", sa.BigInteger(), server_default=ZERO, nullable=False),
        sa.Column("latency_ms", sa.Integer(), server_default=ZERO, nullable=False),
        sa.Column("outcome", sa.String(20), nullable=False),
        _created_at(),
    )
    op.create_index("ix_usage_log_account_id", "usage_log", ["account_id"])
    op.create_index("ix_usage_log_created_at", "usage_log", ["created_at"])


def downgrade() -> None:
    for table in ("usage_log", "ledger", "reservation_items", "reservations", "quota_buckets", "sites", "accounts"):
        op.drop_table(table)
