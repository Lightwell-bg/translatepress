"""API error type, the single JSON error format and the exception handlers.

Format everywhere: {"error": {"code": ..., "message": ...}}. Messages are fixed
strings: neither client input nor provider/internal error text ever goes out.
"""

from __future__ import annotations

import logging
from typing import Any

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException as StarletteHTTPException

from app import quota
from app.providers import base as pb
from app.translate_service import SpendLimitReached

log = logging.getLogger("lingocanvas.api")


class ApiError(Exception):
    def __init__(
        self,
        status: int,
        code: str,
        message: str,
        *,
        headers: dict[str, str] | None = None,
        extra: dict[str, Any] | None = None,
    ) -> None:
        super().__init__(code)
        self.status = status
        self.code = code
        self.message = message
        self.headers = headers or {}
        self.extra = extra or {}


def error_response(
    status: int,
    code: str,
    message: str,
    *,
    headers: dict[str, str] | None = None,
    extra: dict[str, Any] | None = None,
) -> JSONResponse:
    body: dict[str, Any] = {"code": code, "message": message}
    if extra:
        body.update(extra)
    return JSONResponse({"error": body}, status_code=status, headers=headers)


_CONFLICT_MESSAGES = {
    "request_in_progress": "This request is still being processed.",
    "request_id_conflict": "This request_id was already used for a different request.",
    "replay_limit": "This request was already delivered the maximum number of times.",
    "retry_limit": "Too many attempts for this request_id.",
}


async def _api_error(_: Request, exc: ApiError) -> JSONResponse:
    return error_response(exc.status, exc.code, exc.message, headers=exc.headers, extra=exc.extra)


def _mask_loc(loc: Any) -> list[str]:
    """Error path with any element that follows ``items``/``glossary`` replaced by ``<key>``."""
    out: list[str] = []
    previous = None
    for part in loc:
        out.append("<key>" if previous in ("items", "glossary") else str(part))
        previous = part
    return out


async def _validation_error(_: Request, exc: RequestValidationError) -> JSONResponse:
    # Only field path, generic message and error type: FastAPI's default includes
    # the offending input (client text), and dict keys in the path (items,
    # glossary) are client text too, so they are masked.
    problems = [
        {
            "field": ".".join(_mask_loc(err.get("loc", ()))),
            "message": str(err.get("msg", "invalid value")),
            "type": str(err.get("type", "value_error")),
        }
        for err in exc.errors()
    ]
    return error_response(422, "validation_error", "The request is invalid.", extra={"details": problems})


async def _http_error(_: Request, exc: StarletteHTTPException) -> JSONResponse:
    code = {404: "not_found", 405: "method_not_allowed"}.get(exc.status_code, "http_error")
    message = {404: "Not found.", 405: "Method not allowed."}.get(exc.status_code, "Request failed.")
    return error_response(exc.status_code, code, message, headers=getattr(exc, "headers", None))


async def _quota_error(_: Request, exc: quota.QuotaError) -> JSONResponse:
    if isinstance(exc, quota.InsufficientQuota):
        return error_response(
            402, "insufficient_quota", "Not enough characters left.",
            extra={"needed": exc.needed, "available": exc.available},
        )
    if isinstance(exc, quota.AccountSuspended):
        return error_response(403, "account_suspended", "This account is suspended.")
    if isinstance(exc, quota.TooManyInFlight):
        return error_response(
            429, "too_many_in_flight", "Too many requests are being processed.",
            headers={"Retry-After": str(exc.retry_after)},
        )
    if isinstance(exc, quota.RateLimited):
        return error_response(
            429, "rate_limited", "Too many requests.", headers={"Retry-After": str(exc.retry_after)}
        )
    if exc.code in _CONFLICT_MESSAGES:
        return error_response(409, exc.code, _CONFLICT_MESSAGES[exc.code])
    log.error("unmapped quota error %s", type(exc).__name__)
    return error_response(500, "internal_error", "Internal error.")


async def _provider_error(_: Request, exc: pb.ProviderError) -> JSONResponse:
    # The provider's own message is never passed on.
    if isinstance(exc, pb.ProviderTimeout):
        return error_response(504, "provider_timeout", "The translation provider timed out.")
    if isinstance(exc, pb.ProviderRateLimited):
        return error_response(
            503, "provider_busy", "The translation provider is busy. Try again later.",
            headers={"Retry-After": "10"},
        )
    if isinstance(exc, pb.ProviderUnavailable):
        return error_response(503, "provider_unavailable", "The translation provider is unavailable.")
    if isinstance(exc, pb.ProviderBadOutput):
        return error_response(502, "provider_bad_output", "The provider returned an unusable answer.")
    return error_response(502, "provider_error", "The translation provider failed.")


async def _spend_limit(_: Request, exc: SpendLimitReached) -> JSONResponse:
    return error_response(
        503, "service_budget_exhausted", "The service is temporarily out of capacity.",
        headers={"Retry-After": "3600"},
    )


async def _unhandled(_: Request, exc: Exception) -> JSONResponse:
    log.error("unhandled %s", type(exc).__name__, exc_info=exc)
    return error_response(500, "internal_error", "Internal error.")


def install_handlers(app: FastAPI) -> None:
    app.add_exception_handler(ApiError, _api_error)
    app.add_exception_handler(RequestValidationError, _validation_error)
    app.add_exception_handler(StarletteHTTPException, _http_error)
    app.add_exception_handler(quota.QuotaError, _quota_error)
    app.add_exception_handler(pb.ProviderError, _provider_error)
    app.add_exception_handler(SpendLimitReached, _spend_limit)
    app.add_exception_handler(Exception, _unhandled)
