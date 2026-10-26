"""Settings: secrets and critical values from .env, constants from config.ini."""

from __future__ import annotations

import configparser
from dataclasses import dataclass
from datetime import time
from decimal import Decimal
from functools import cached_property
from pathlib import Path
from typing import Literal

from pydantic import Field, SecretStr, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

ROOT = Path(__file__).resolve().parent.parent


@dataclass(frozen=True)
class DeepSeekConfig:
    base_url: str
    model: str
    timeout_seconds: float
    max_output_tokens: int


@dataclass(frozen=True)
class PricingConfig:
    peak_cache_hit: Decimal  # USD per 1M tokens
    peak_cache_miss: Decimal
    peak_output: Decimal
    offpeak_multiplier: Decimal
    peak_windows: tuple[tuple[time, time], ...]  # UTC, Monday-Friday


@dataclass(frozen=True)
class LimitsConfig:
    max_items: int
    max_chars_per_request: int
    max_chars_per_item: int
    max_body_bytes: int
    max_glossary_entries: int
    per_site_requests_per_minute: int
    max_in_flight_per_account: int
    reservation_ttl_seconds: int
    max_replays: int
    max_attempts: int
    sweeper_interval_seconds: int
    glossary_max_chars: int
    # Overall budget for all provider calls of one request; must be >= 30s below reservation_ttl.
    call_deadline_seconds: float


@dataclass(frozen=True)
class PlanConfig:
    chars_per_month: int
    max_sites: int


@dataclass(frozen=True)
class AppConfig:
    deepseek: DeepSeekConfig
    pricing: PricingConfig
    limits: LimitsConfig
    plans: dict[str, PlanConfig]
    packs: dict[str, int]


def _parse_windows(raw: str) -> tuple[tuple[time, time], ...]:
    windows: list[tuple[time, time]] = []
    for part in raw.split(","):
        part = part.strip()
        if not part:
            continue
        start, end = part.split("-")
        windows.append((time.fromisoformat(start.strip()), time.fromisoformat(end.strip())))
    return tuple(windows)


def load_config(path: str | Path) -> AppConfig:
    parser = configparser.ConfigParser(inline_comment_prefixes=None)
    with open(path, encoding="utf-8") as fh:
        parser.read_file(fh)
    d, p, lim = parser["deepseek"], parser["pricing"], parser["limits"]
    plans: dict[str, PlanConfig] = {}
    for name, value in parser["plans"].items():
        chars, sites = value.split(":")
        plans[name] = PlanConfig(int(chars), int(sites))
    return AppConfig(
        deepseek=DeepSeekConfig(
            base_url=d["base_url"].rstrip("/"),
            model=d["model"],
            timeout_seconds=float(d["timeout_seconds"]),
            max_output_tokens=int(d["max_output_tokens"]),
        ),
        pricing=PricingConfig(
            peak_cache_hit=Decimal(p["peak_cache_hit"]),
            peak_cache_miss=Decimal(p["peak_cache_miss"]),
            peak_output=Decimal(p["peak_output"]),
            offpeak_multiplier=Decimal(p["offpeak_multiplier"]),
            peak_windows=_parse_windows(p["peak_windows"]),
        ),
        limits=LimitsConfig(
            **{
                f: (float(lim[f]) if f == "call_deadline_seconds" else int(lim[f]))
                for f in LimitsConfig.__dataclass_fields__
            }
        ),
        plans=plans,
        packs={name: int(v) for name, v in parser["packs"].items()},
    )


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=ROOT / ".env", env_file_encoding="utf-8", extra="ignore")

    database_url: str
    deepseek_api_key: SecretStr = SecretStr("")
    token_pepper: SecretStr
    provider: Literal["deepseek", "mock"] = "mock"
    daily_spend_limit_usd: Decimal = Decimal("5")
    config_ini: Path = Field(default=ROOT / "config.ini")

    @field_validator("token_pepper")
    @classmethod
    def _pepper_long_enough(cls, v: SecretStr) -> SecretStr:
        if len(v.get_secret_value()) < 32:
            raise ValueError("TOKEN_PEPPER must be at least 32 characters")
        return v

    @cached_property
    def cfg(self) -> AppConfig:
        return load_config(self.config_ini)

    @property
    def daily_spend_limit_micro_usd(self) -> int:
        return int(self.daily_spend_limit_usd * 1_000_000)
