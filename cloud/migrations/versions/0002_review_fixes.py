"""review fixes: attempt timestamps, replay slots, returned_chars

Revision ID: 0002
Revises: 0001
Create Date: 2026-10-26
"""
from alembic import op
import sqlalchemy as sa

revision = "0002"
down_revision = "0001"
branch_labels = None
depends_on = None


def upgrade() -> None:
    # last_attempt_at: backfilled from created_at, then NOT NULL with a default.
    op.add_column("reservations", sa.Column("last_attempt_at", sa.DateTime(timezone=True), nullable=True))
    op.execute("UPDATE reservations SET last_attempt_at = created_at")
    op.alter_column(
        "reservations", "last_attempt_at", nullable=False, server_default=sa.text("now()"),
        existing_type=sa.DateTime(timezone=True),
    )
    op.add_column("reservations", sa.Column("replay_started_at", sa.DateTime(timezone=True), nullable=True))
    op.drop_index("ix_reservations_site_created", table_name="reservations")
    op.create_index("ix_reservations_site_attempt", "reservations", ["site_id", "last_attempt_at"])
    # Provider work, not customer billing.
    op.alter_column("usage_log", "billed_chars", new_column_name="returned_chars")


def downgrade() -> None:
    op.alter_column("usage_log", "returned_chars", new_column_name="billed_chars")
    op.drop_index("ix_reservations_site_attempt", table_name="reservations")
    op.create_index("ix_reservations_site_created", "reservations", ["site_id", "created_at"])
    op.drop_column("reservations", "replay_started_at")
    op.drop_column("reservations", "last_attempt_at")
