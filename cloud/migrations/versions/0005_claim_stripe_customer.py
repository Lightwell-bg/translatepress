"""checkout_claims.stripe_customer_id: customer created by the backend with lc_app metadata

Revision ID: 0005
Revises: 0004
Create Date: 2026-10-26
"""
from alembic import op
import sqlalchemy as sa


revision = '0005'
down_revision = '0004'
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column('checkout_claims', sa.Column('stripe_customer_id', sa.String(length=255), nullable=True))


def downgrade() -> None:
    op.drop_column('checkout_claims', 'stripe_customer_id')
