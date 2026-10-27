"""checkout_claims: site_id, consumed_at, recovery_until and status 'confirmed' (lost-response token recovery)

Revision ID: 0006
Revises: 0005
Create Date: 2026-10-27
"""
from alembic import op
import sqlalchemy as sa


revision = '0006'
down_revision = '0005'
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column('checkout_claims', sa.Column('site_id', sa.BigInteger(), nullable=True))
    op.add_column('checkout_claims', sa.Column('consumed_at', sa.DateTime(timezone=True), nullable=True))
    op.add_column('checkout_claims', sa.Column('recovery_until', sa.DateTime(timezone=True), nullable=True))
    op.create_foreign_key(
        'checkout_claims_site_id_fkey', 'checkout_claims', 'sites', ['site_id'], ['id'], ondelete='RESTRICT'
    )
    op.drop_constraint('ck_claims_status', 'checkout_claims', type_='check')
    op.create_check_constraint(
        'ck_claims_status', 'checkout_claims', "status IN ('open','paid','consumed','confirmed','expired')"
    )


def downgrade() -> None:
    # 'confirmed' rows were consumed claims; the old constraint does not know the new status.
    op.execute("UPDATE checkout_claims SET status = 'consumed' WHERE status = 'confirmed'")
    op.drop_constraint('ck_claims_status', 'checkout_claims', type_='check')
    op.create_check_constraint('ck_claims_status', 'checkout_claims', "status IN ('open','paid','consumed','expired')")
    op.drop_constraint('checkout_claims_site_id_fkey', 'checkout_claims', type_='foreignkey')
    op.drop_column('checkout_claims', 'recovery_until')
    op.drop_column('checkout_claims', 'consumed_at')
    op.drop_column('checkout_claims', 'site_id')
