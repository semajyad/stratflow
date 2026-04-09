#!/usr/bin/env python3
"""Launcher for the MySQL MCP server, pulling credentials from stratflow/.env.

Avoids hardcoding DB credentials in .mcp.json (which gets committed). Reads
DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD from the project .env,
exports them under the env names the MCP server expects, and execs the server.

The server is configured as READ-ONLY — no INSERT/UPDATE/DELETE. Schema
changes must go through proper migration files, not Claude's tool calls.
"""
import os
import re
import sys
from pathlib import Path

ENV_PATH = Path(__file__).resolve().parent.parent.parent / ".env"

# (env var in .env) -> (env var the MCP server reads)
MAPPING = {
    "DB_HOST":     "MYSQL_HOST",
    "DB_PORT":     "MYSQL_PORT",
    "DB_DATABASE": "MYSQL_DB",
    "DB_USERNAME": "MYSQL_USER",
    "DB_PASSWORD": "MYSQL_PASS",
}


def load_env_file() -> dict[str, str]:
    if not ENV_PATH.is_file():
        return {}
    out: dict[str, str] = {}
    try:
        for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            m = re.match(r"^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$", line)
            if m:
                out[m.group(1)] = m.group(2).strip().strip('"').strip("'")
    except OSError as e:
        print(f"[mysql-mcp] could not read {ENV_PATH}: {e}", file=sys.stderr)
    return out


def main() -> int:
    env = load_env_file()
    if not env:
        print(f"[mysql-mcp] {ENV_PATH} missing or empty", file=sys.stderr)
        return 1

    for src_key, dest_key in MAPPING.items():
        if src_key in env:
            os.environ[dest_key] = env[src_key]

    # Localhost override: StratFlow's .env uses DB_HOST=mysql (the Docker
    # service name). From the host, we need 127.0.0.1 instead.
    if os.environ.get("MYSQL_HOST") == "mysql":
        os.environ["MYSQL_HOST"] = "127.0.0.1"

    # Read-only enforcement
    os.environ["ALLOW_INSERT_OPERATION"] = "false"
    os.environ["ALLOW_UPDATE_OPERATION"] = "false"
    os.environ["ALLOW_DELETE_OPERATION"] = "false"

    try:
        os.execvp("npx", ["npx", "-y", "@benborla29/mcp-server-mysql"])
    except FileNotFoundError:
        print("[mysql-mcp] npx not found on PATH", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
