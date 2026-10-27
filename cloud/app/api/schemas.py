"""Request/response models of the public API."""

from __future__ import annotations

from datetime import datetime
from typing import Annotated, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator

REQUEST_ID_PATTERN = r"^[A-Za-z0-9_-]{8,64}$"
LOCALE_PATTERN = r"^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$"

Locale = Annotated[str, Field(max_length=16, pattern=LOCALE_PATTERN)]


class TranslateBody(BaseModel):
    model_config = ConfigDict(extra="ignore")

    request_id: str = Field(pattern=REQUEST_ID_PATTERN)
    source_locale: Locale
    target_locale: Locale
    target_label: str | None = Field(default=None, max_length=64)
    items: dict[str, str]
    glossary: dict[str, str] = Field(default_factory=dict)

    @field_validator("items")
    @classmethod
    def _check_items(cls, items: dict[str, str]) -> dict[str, str]:
        if not items:
            raise ValueError("items must not be empty")
        for key, text in items.items():
            if not 1 <= len(key) <= 128 or not key.isprintable():
                raise ValueError("item keys must be 1-128 printable characters")
            if not text.strip():
                raise ValueError("item texts must not be empty")
        return items

    @field_validator("glossary")
    @classmethod
    def _check_glossary(cls, glossary: dict[str, str]) -> dict[str, str]:
        for src, dst in glossary.items():
            if not src.strip() or not dst.strip() or len(src) > 200 or len(dst) > 200:
                raise ValueError("glossary entries must be non-empty and at most 200 characters")
        return glossary


class QuotaOut(BaseModel):
    monthly_remaining: int
    packs_remaining: int
    packs_usable: bool
    total_remaining: int


class TranslateOut(BaseModel):
    request_id: str
    translations: dict[str, str]
    missing: list[str]
    billed_chars: int
    replay: bool
    quota: QuotaOut


class AccountInfo(BaseModel):
    id: int
    name: str
    plan: str | None
    status: str


class SitesInfo(BaseModel):
    used: int
    max: int | None


class SubscriptionOut(BaseModel):
    status: str
    current_period_end: datetime | None
    cancel_at_period_end: bool


class AccountOut(BaseModel):
    account: AccountInfo
    sites: SitesInfo
    quota: QuotaOut
    billed_chars_this_month: int
    subscription: SubscriptionOut | None = None


# --- billing ---------------------------------------------------------------

SHA256_HEX_PATTERN = r"^[0-9a-fA-F]{64}$"
EMAIL_PATTERN = r"^[^@\s]+@[^@\s]+\.[^@\s]+$"


class SignupBody(BaseModel):
    model_config = ConfigDict(extra="ignore")

    plan: Literal["starter"]
    site_name: str = Field(min_length=1, max_length=255)
    claim_secret_sha256: str = Field(pattern=SHA256_HEX_PATTERN)
    email: str | None = Field(default=None, max_length=320, pattern=EMAIL_PATTERN)

    @field_validator("site_name")
    @classmethod
    def _site_name_not_blank(cls, value: str) -> str:
        if not value.strip():
            raise ValueError("site_name must not be blank")
        return value


class SignupOut(BaseModel):
    claim_id: str
    checkout_url: str
    expires_at: datetime


class ClaimBody(BaseModel):
    model_config = ConfigDict(extra="ignore")

    claim_id: str = Field(min_length=1, max_length=64)
    claim_secret: str = Field(min_length=1, max_length=200)


class ClaimOut(BaseModel):
    token: str
    site_id: int
    account_id: int
    recovered: bool


class ClaimConfirmBody(BaseModel):
    model_config = ConfigDict(extra="ignore")

    claim_id: str = Field(min_length=1, max_length=64)


class CheckoutBody(BaseModel):
    model_config = ConfigDict(extra="ignore")

    product: Literal["pack_250k", "starter"]


class CheckoutOut(BaseModel):
    checkout_url: str


class PortalOut(BaseModel):
    url: str


class PairCodeOut(BaseModel):
    code: str
    expires_at: datetime


class PairBody(BaseModel):
    model_config = ConfigDict(extra="ignore")

    code: str = Field(min_length=1, max_length=100)
    site_name: str = Field(min_length=1, max_length=255)

    @field_validator("site_name")
    @classmethod
    def _site_name_not_blank(cls, value: str) -> str:
        if not value.strip():
            raise ValueError("site_name must not be blank")
        return value


class PairOut(BaseModel):
    token: str
    site_id: int
