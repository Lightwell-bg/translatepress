"""Provider cost in integer micro-USD (1e-6 USD), rounded up."""

from __future__ import annotations

from datetime import datetime, timezone
from decimal import ROUND_CEILING, Decimal

from app.config import PricingConfig
from app.providers.base import Usage


class Pricing:
    def __init__(self, cfg: PricingConfig) -> None:
        self._cfg = cfg

    def is_peak(self, at: datetime) -> bool:
        at = at.astimezone(timezone.utc) if at.tzinfo else at.replace(tzinfo=timezone.utc)
        if at.weekday() >= 5:  # Saturday, Sunday
            return False
        t = at.time()
        return any(start <= t < end for start, end in self._cfg.peak_windows)

    def cost_micro_usd(self, usage: Usage, at: datetime) -> int:
        hit = max(0, usage.cache_hit_tokens)
        miss = usage.cache_miss_tokens
        if miss <= 0:
            miss = max(0, usage.prompt_tokens - hit)
        # reasoning tokens are already included in completion_tokens
        out = max(0, usage.completion_tokens)
        c = self._cfg
        # price is USD per 1M tokens == micro-USD per token
        total = hit * c.peak_cache_hit + miss * c.peak_cache_miss + out * c.peak_output
        if not self.is_peak(at):
            total *= c.offpeak_multiplier
        return int(total.to_integral_value(rounding=ROUND_CEILING))

    def worst_case_micro_usd(self, prompt_tokens: int, max_output_tokens: int) -> int:
        """Upper bound of one call: all prompt tokens as cache misses, all output tokens, peak prices."""
        c = self._cfg
        total = max(0, prompt_tokens) * c.peak_cache_miss + max(0, max_output_tokens) * c.peak_output
        return int(total.to_integral_value(rounding=ROUND_CEILING))
