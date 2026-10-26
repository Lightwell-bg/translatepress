"""stripe review fixes: revoke_pending, dispute hold, disputes, parked charge events, pending checkout

Revision ID: 0004
Revises: 0003
Create Date: 2026-10-26
"""
from alembic import op
import sqlalchemy as sa


revision = '0004'
down_revision = '0003'
branch_labels = None
depends_on = None


def upgrade() -> None:
    # Existing rows get the defaults (0 / false / NULL): nothing to backfill.
    op.add_column('accounts', sa.Column('dispute_hold', sa.Boolean(), server_default='false', nullable=False))
    op.add_column('accounts', sa.Column('pending_checkout_session_id', sa.String(length=255), nullable=True))
    op.add_column('accounts', sa.Column('pending_checkout_url', sa.String(length=2048), nullable=True))
    op.add_column('accounts', sa.Column('pending_checkout_expires_at', sa.DateTime(timezone=True), nullable=True))

    op.add_column('quota_buckets', sa.Column('revoke_pending', sa.BigInteger(), server_default='0', nullable=False))
    op.create_check_constraint(
        'ck_buckets_revoke_pending', 'quota_buckets', 'revoke_pending >= 0 AND revoke_pending <= reserved'
    )

    # NOT VALID: enforced for every new/updated row, but a zero-amount row written
    # by an earlier build (if any) does not block the upgrade.
    op.execute('ALTER TABLE payments ADD CONSTRAINT ck_payments_paid CHECK (amount_cents > 0) NOT VALID')

    op.create_table('disputes',
    sa.Column('stripe_dispute_id', sa.String(length=255), nullable=False),
    sa.Column('payment_id', sa.BigInteger(), nullable=False),
    sa.Column('status', sa.String(length=40), nullable=False),
    sa.Column('terminal', sa.Boolean(), server_default='false', nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.ForeignKeyConstraint(['payment_id'], ['payments.id'], ondelete='RESTRICT'),
    sa.PrimaryKeyConstraint('stripe_dispute_id')
    )
    op.create_index(op.f('ix_disputes_payment_id'), 'disputes', ['payment_id'], unique=False)
    op.create_table('unmatched_charge_events',
    sa.Column('id', sa.BigInteger(), nullable=False),
    sa.Column('stripe_event_id', sa.String(length=255), nullable=False),
    sa.Column('kind', sa.String(length=10), nullable=False),
    sa.Column('charge_id', sa.String(length=255), nullable=True),
    sa.Column('payment_intent_id', sa.String(length=255), nullable=True),
    sa.Column('amount_refunded', sa.BigInteger(), server_default='0', nullable=False),
    sa.Column('dispute_id', sa.String(length=255), nullable=True),
    sa.Column('dispute_status', sa.String(length=40), nullable=True),
    sa.Column('applied_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.PrimaryKeyConstraint('id'),
    sa.UniqueConstraint('stripe_event_id')
    )
    op.create_index(op.f('ix_unmatched_charge_events_charge_id'), 'unmatched_charge_events', ['charge_id'], unique=False)
    op.create_index(op.f('ix_unmatched_charge_events_created_at'), 'unmatched_charge_events', ['created_at'], unique=False)
    op.create_index(op.f('ix_unmatched_charge_events_payment_intent_id'), 'unmatched_charge_events', ['payment_intent_id'], unique=False)


def downgrade() -> None:
    op.drop_index(op.f('ix_unmatched_charge_events_payment_intent_id'), table_name='unmatched_charge_events')
    op.drop_index(op.f('ix_unmatched_charge_events_created_at'), table_name='unmatched_charge_events')
    op.drop_index(op.f('ix_unmatched_charge_events_charge_id'), table_name='unmatched_charge_events')
    op.drop_table('unmatched_charge_events')
    op.drop_index(op.f('ix_disputes_payment_id'), table_name='disputes')
    op.drop_table('disputes')
    op.drop_constraint('ck_payments_paid', 'payments', type_='check')
    op.drop_constraint('ck_buckets_revoke_pending', 'quota_buckets', type_='check')
    op.drop_column('quota_buckets', 'revoke_pending')
    op.drop_column('accounts', 'pending_checkout_expires_at')
    op.drop_column('accounts', 'pending_checkout_url')
    op.drop_column('accounts', 'pending_checkout_session_id')
    op.drop_column('accounts', 'dispute_hold')
