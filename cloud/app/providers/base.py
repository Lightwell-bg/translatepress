"""Contract between the translation service and an AI provider."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Protocol


@dataclass(frozen=True)
class ProviderRequest:
    source_locale: str
    target_locale: str
    target_label: str
    # Short ids ("1", "2", ...) -> source text. Never the client's 64-hex keys:
    # those cost ~39 tokens each and the model has to echo them back.
    items: dict[str, str]
    glossary: dict[str, str] = field(default_factory=dict)
    max_tokens: int = 4096


@dataclass(frozen=True)
class Usage:
    prompt_tokens: int = 0
    completion_tokens: int = 0
    cache_hit_tokens: int = 0
    cache_miss_tokens: int = 0
    reasoning_tokens: int = 0


@dataclass(frozen=True)
class ProviderResult:
    # Only ids the provider returned as non-empty strings.
    translations: dict[str, str]
    usage: Usage
    model: str
    finish_reason: str | None
    latency_ms: int


class ProviderError(Exception):
    """Base provider failure. ``outcome`` goes to usage_log, ``code`` to the API."""

    outcome = "provider_error"
    code = "provider_error"
    # Tokens may have been billed even though the call failed (e.g. bad JSON).
    usage: Usage | None = None
    # Could the provider have processed (and billed) the request? When True and
    # ``usage`` is unknown, the service books a worst-case cost against the
    # daily spend limit instead of zero.
    maybe_billed: bool = True


class ProviderTimeout(ProviderError):
    # The provider may still have processed (and billed) the request: never retried.
    outcome = "timeout"
    code = "provider_timeout"


class ProviderRateLimited(ProviderError):
    outcome = "rate_limited"
    code = "provider_busy"
    maybe_billed = False


class ProviderUnavailable(ProviderError):
    """5xx (ambiguous, maybe_billed) or a connect-phase failure (not sent)."""

    code = "provider_unavailable"

    def __init__(self, message: str, *, maybe_billed: bool = True) -> None:
        super().__init__(message)
        self.maybe_billed = maybe_billed


class ProviderBadOutput(ProviderError):
    outcome = "bad_output"
    code = "provider_bad_output"

    def __init__(self, message: str, usage: Usage | None = None) -> None:
        super().__init__(message)
        self.usage = usage


class ProviderRejected(ProviderError):
    """Non-retryable 4xx (bad request, auth): the provider did not run inference."""

    code = "provider_error"
    maybe_billed = False


class TranslationProvider(Protocol):
    model: str

    async def translate(self, request: ProviderRequest) -> ProviderResult: ...
