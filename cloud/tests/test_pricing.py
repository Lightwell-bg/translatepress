"""Provider cost: peak windows (UTC, Mon-Fri), off-peak multiplier, cache split, round-up to micro-USD."""

from __future__ import annotations

from datetime import datetime, timedelta, timezone

import pytest

from app.config import ROOT, load_config
from app.pricing import Pricing
from app.providers.base import Usage

PRICING = Pricing(load_config(ROOT / "config.ini").pricing)

MON = datetime(2026, 10, 26, tzinfo=timezone.utc)  # a Monday
assert MON.weekday() == 0


def at(day: datetime, hhmmss: str) -> datetime:
    h, m, s = (int(p) for p in hhmmss.split(":"))
    return day.replace(hour=h, minute=m, second=s)


@pytest.mark.parametrize(
    "clock, peak",
    [
        ("00:00:00", False),
        ("00:59:59", False),
        ("01:00:00", True),
        ("02:30:00", True),
        ("03:59:59", True),
        ("04:00:00", False),
        ("05:59:59", False),
        ("06:00:00", True),
        ("09:59:59", True),
        ("10:00:00", False),
        ("23:59:59", False),
    ],
)
def test_peak_window_edges_on_a_weekday(clock, peak):
    for offset in range(5):  # Monday .. Friday behave the same
        assert PRICING.is_peak(at(MON + timedelta(days=offset), clock)) is peak


@pytest.mark.parametrize("offset", [5, 6])
@pytest.mark.parametrize("clock", ["01:00:00", "03:00:00", "06:00:00", "09:00:00"])
def test_weekend_is_never_peak(offset, clock):
    day = MON + timedelta(days=offset)
    assert day.weekday() >= 5
    assert PRICING.is_peak(at(day, clock)) is False


def test_windows_are_evaluated_in_utc():
    plus2 = timezone(timedelta(hours=2))
    assert PRICING.is_peak(datetime(2026, 10, 26, 3, 0, tzinfo=plus2)) is True  # 01:00 UTC
    assert PRICING.is_peak(datetime(2026, 10, 26, 2, 59, tzinfo=plus2)) is False  # 00:59 UTC
    minus5 = timezone(timedelta(hours=-5))
    assert PRICING.is_peak(datetime(2026, 10, 25, 20, 0, tzinfo=minus5)) is True  # Mon 01:00 UTC, still Sunday locally
    assert PRICING.is_peak(datetime(2026, 10, 26, 22, 0, tzinfo=minus5)) is True  # Tue 03:00 UTC
    assert PRICING.is_peak(datetime(2026, 10, 26, 18, 0, tzinfo=minus5)) is False  # Mon 23:00 UTC
    # naive datetimes are taken as UTC
    assert PRICING.is_peak(datetime(2026, 10, 26, 2, 0)) is True


USAGE = Usage(prompt_tokens=1000, completion_tokens=500, cache_hit_tokens=400, cache_miss_tokens=600)


def test_peak_cost_uses_hit_miss_and_output_prices():
    # 400*0.006 + 600*0.30 + 500*1.20 = 2.4 + 180 + 600 = 782.4 -> rounded up
    assert PRICING.cost_micro_usd(USAGE, at(MON, "02:00:00")) == 783


def test_offpeak_cost_is_half_and_rounded_up():
    assert PRICING.cost_micro_usd(USAGE, at(MON, "12:00:00")) == 392  # 391.2 -> 392
    assert PRICING.cost_micro_usd(USAGE, at(MON + timedelta(days=5), "02:00:00")) == 392


def test_miss_tokens_are_derived_when_the_provider_gave_none():
    derived = Usage(prompt_tokens=1000, completion_tokens=500, cache_hit_tokens=400)
    assert PRICING.cost_micro_usd(derived, at(MON, "02:00:00")) == 783


def test_cache_hit_is_much_cheaper_than_miss():
    hit = Usage(prompt_tokens=1000, cache_hit_tokens=1000)
    miss = Usage(prompt_tokens=1000, cache_miss_tokens=1000)
    when = at(MON, "02:00:00")
    assert PRICING.cost_micro_usd(hit, when) == 6
    assert PRICING.cost_micro_usd(miss, when) == 300


def test_reasoning_tokens_are_not_billed_twice():
    base = Usage(completion_tokens=500)
    with_reasoning = Usage(completion_tokens=500, reasoning_tokens=300)
    when = at(MON, "02:00:00")
    assert PRICING.cost_micro_usd(base, when) == PRICING.cost_micro_usd(with_reasoning, when) == 600


def test_any_nonzero_usage_costs_at_least_one_micro_usd():
    assert PRICING.cost_micro_usd(Usage(cache_hit_tokens=1), at(MON, "02:00:00")) == 1  # 0.006 -> 1
    assert PRICING.cost_micro_usd(Usage(cache_hit_tokens=1), at(MON, "12:00:00")) == 1  # 0.003 -> 1
    assert PRICING.cost_micro_usd(Usage(), at(MON, "02:00:00")) == 0
