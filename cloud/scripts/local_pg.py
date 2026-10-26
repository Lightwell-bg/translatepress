"""Start/stop a persistent local PostgreSQL cluster (pgserver) in ROOT/.pgdata.

Usage:
    python scripts/local_pg.py start   # starts (or reuses) the cluster, prints DATABASE_URL
    python scripts/local_pg.py url     # same as start
    python scripts/local_pg.py stop    # stops the cluster

The database ``lingocanvas`` is created on first start. Local development only.
"""

from __future__ import annotations

import sys
from pathlib import Path

import pgserver

ROOT = Path(__file__).resolve().parent.parent
PGDATA = ROOT / ".pgdata"
DB_NAME = "lingocanvas"


def _server() -> "pgserver.PostgresServer":
    return pgserver.get_server(PGDATA, cleanup_mode=None)


def database_url(srv: "pgserver.PostgresServer") -> str:
    """Build a SQLAlchemy asyncpg URL (unix socket on Linux/mac, TCP on Windows)."""
    uri = srv.get_uri(DB_NAME)
    return uri.replace("postgresql://", "postgresql+asyncpg://", 1)


def start() -> str:
    srv = _server()
    exists = srv.psql(f"SELECT 1 FROM pg_database WHERE datname = '{DB_NAME}'")
    if "1" not in exists.replace("(0 rows)", ""):
        srv.psql(f'CREATE DATABASE "{DB_NAME}"')
    return database_url(srv)


def stop() -> None:
    _server().cleanup()


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else "start"
    if cmd in ("start", "url"):
        print(start())
    elif cmd == "stop":
        stop()
        print("stopped")
    else:
        raise SystemExit("usage: local_pg.py start|url|stop")
