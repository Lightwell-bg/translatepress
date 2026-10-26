# LingoCanvas Cloud API image (for a FUTURE VPS deployment; not built or run yet).
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app

COPY requirements.txt ./
RUN pip install -r requirements.txt

COPY alembic.ini config.ini ./
COPY app ./app
COPY migrations ./migrations

# Run as an unprivileged user.
RUN useradd --system --create-home --uid 10001 lingocanvas \
    && chown -R lingocanvas:lingocanvas /app
USER lingocanvas

EXPOSE 8000

# Secrets (DATABASE_URL, TOKEN_PEPPER, DEEPSEEK_API_KEY, ...) come from the environment, never from the image.
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--proxy-headers"]
