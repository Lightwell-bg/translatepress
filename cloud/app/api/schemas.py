"""Request/response models of the public API."""

from __future__ import annotations

from typing import Annotated

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


class AccountOut(BaseModel):
    account: AccountInfo
    sites: SitesInfo
    quota: QuotaOut
    billed_chars_this_month: int
