#!/usr/bin/env python3
"""OAuth-protected MCP endpoint for BalanzaPro VPS administration."""
from __future__ import annotations

import asyncio
import hashlib
import hmac
import html
import json
import os
import secrets
import sqlite3
import subprocess
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any
from urllib.parse import parse_qs, urlencode, urlparse, urlunparse

from mcp.server.auth.provider import AccessToken, AuthorizationCode, AuthorizationParams, OAuthClientInformationFull, OAuthToken, RefreshToken
from mcp.server.auth.settings import AuthSettings, ClientRegistrationOptions, RevocationOptions
from mcp.server.fastmcp import FastMCP
from starlette.requests import Request
from starlette.responses import HTMLResponse, PlainTextResponse, RedirectResponse


ADMIN_SCOPE = "vps.admin"
ACCESS_TOKEN_SECONDS = 8 * 60 * 60
REFRESH_TOKEN_SECONDS = 30 * 24 * 60 * 60


@dataclass(frozen=True)
class Settings:
    bind: str
    port: int
    issuer_url: str
    resource_url: str
    state_db: Path
    password_hash: str


def load_settings() -> Settings:
    password_hash = os.environ.get("BALANZAPRO_VPS_MCP_ADMIN_PASSWORD_SHA256", "").lower()
    if len(password_hash) != 64 or any(char not in "0123456789abcdef" for char in password_hash):
        raise RuntimeError("BALANZAPRO_VPS_MCP_ADMIN_PASSWORD_SHA256 debe ser un SHA-256 hexadecimal.")
    return Settings(
        bind=os.environ.get("BALANZAPRO_VPS_MCP_BIND", "127.0.0.1"),
        port=int(os.environ.get("BALANZAPRO_VPS_MCP_PORT", "17890")),
        issuer_url=os.environ.get("BALANZAPRO_VPS_MCP_ISSUER_URL", "https://app.balanzapro.com/mcp-admin").rstrip("/"),
        resource_url=os.environ.get("BALANZAPRO_VPS_MCP_RESOURCE_URL", "https://app.balanzapro.com/mcp-admin/mcp"),
        state_db=Path(os.environ.get("BALANZAPRO_VPS_MCP_STATE_DB", "/var/lib/balanzapro-vps-mcp/oauth.sqlite3")),
        password_hash=password_hash,
    )


def digest(value: str) -> str:
    return hashlib.sha256(value.encode()).hexdigest()


class Store:
    def __init__(self, database: Path) -> None:
        self.database = database
        self.database.parent.mkdir(parents=True, exist_ok=True)
        self.initialize()

    def connection(self) -> sqlite3.Connection:
        connection = sqlite3.connect(self.database)
        connection.row_factory = sqlite3.Row
        return connection

    def initialize(self) -> None:
        with self.connection() as connection:
            connection.executescript(
                """
                create table if not exists clients (client_id text primary key, payload text not null);
                create table if not exists pending (id text primary key, client_id text not null, scopes text not null, code_challenge text not null, redirect_uri text not null, redirect_uri_explicit integer not null, resource text, expires_at integer not null);
                create table if not exists codes (code_hash text primary key, client_id text not null, scopes text not null, code_challenge text not null, redirect_uri text not null, redirect_uri_explicit integer not null, resource text, subject text not null, expires_at integer not null);
                create table if not exists access_tokens (token_hash text primary key, client_id text not null, scopes text not null, resource text, subject text not null, expires_at integer not null, revoked integer not null default 0);
                create table if not exists refresh_tokens (token_hash text primary key, client_id text not null, scopes text not null, subject text not null, expires_at integer not null, revoked integer not null default 0);
                create table if not exists oauth_states (request_id text primary key, state text, expires_at integer not null);
                """
            )

    def cleanup(self) -> None:
        now = int(time.time())
        with self.connection() as connection:
            for table in ("pending", "codes", "access_tokens", "refresh_tokens", "oauth_states"):
                connection.execute(f"delete from {table} where expires_at < ?", (now,))


class OAuthProvider:
    def __init__(self, settings: Settings, store: Store) -> None:
        self.settings = settings
        self.store = store

    async def get_client(self, client_id: str) -> OAuthClientInformationFull | None:
        with self.store.connection() as connection:
            row = connection.execute("select payload from clients where client_id = ?", (client_id,)).fetchone()
        return OAuthClientInformationFull.model_validate_json(row["payload"]) if row else None

    async def register_client(self, client_info: OAuthClientInformationFull) -> None:
        if not client_info.client_id:
            raise ValueError("El cliente OAuth no tiene client_id.")
        with self.store.connection() as connection:
            connection.execute(
                "insert or replace into clients (client_id, payload) values (?, ?)",
                (client_info.client_id, client_info.model_dump_json()),
            )

    async def authorize(self, client: OAuthClientInformationFull, params: AuthorizationParams) -> str:
        scopes = params.scopes or [ADMIN_SCOPE]
        if set(scopes) != {ADMIN_SCOPE}:
            raise ValueError(f"El unico scope disponible es {ADMIN_SCOPE}.")
        request_id = secrets.token_urlsafe(32)
        with self.store.connection() as connection:
            connection.execute(
                "insert into pending values (?, ?, ?, ?, ?, ?, ?, ?)",
                (request_id, client.client_id, json.dumps(scopes), params.code_challenge, str(params.redirect_uri), int(params.redirect_uri_provided_explicitly), params.resource, int(time.time()) + 300),
            )
            connection.execute("insert into oauth_states values (?, ?, ?)", (request_id, params.state, int(time.time()) + 300))
        return f"{self.settings.issuer_url}/oauth/login?{urlencode({'request': request_id})}"

    async def load_authorization_code(self, client: OAuthClientInformationFull, authorization_code: str) -> AuthorizationCode | None:
        with self.store.connection() as connection:
            row = connection.execute("select * from codes where code_hash = ?", (digest(authorization_code),)).fetchone()
        if not row:
            return None
        return AuthorizationCode(
            code=authorization_code,
            client_id=row["client_id"],
            scopes=json.loads(row["scopes"]),
            code_challenge=row["code_challenge"],
            redirect_uri=row["redirect_uri"],
            redirect_uri_provided_explicitly=bool(row["redirect_uri_explicit"]),
            resource=row["resource"],
            subject=row["subject"],
            expires_at=row["expires_at"],
        )

    async def exchange_authorization_code(self, client: OAuthClientInformationFull, authorization_code: AuthorizationCode) -> OAuthToken:
        with self.store.connection() as connection:
            connection.execute("delete from codes where code_hash = ?", (digest(authorization_code.code),))
        return self.issue_tokens(client.client_id or "", authorization_code.scopes, authorization_code.subject, authorization_code.resource)

    async def load_refresh_token(self, client: OAuthClientInformationFull, refresh_token: str) -> RefreshToken | None:
        with self.store.connection() as connection:
            row = connection.execute("select * from refresh_tokens where token_hash = ? and revoked = 0", (digest(refresh_token),)).fetchone()
        if not row:
            return None
        return RefreshToken(token=refresh_token, client_id=row["client_id"], scopes=json.loads(row["scopes"]), subject=row["subject"], expires_at=row["expires_at"])

    async def exchange_refresh_token(self, client: OAuthClientInformationFull, refresh_token: RefreshToken, scopes: list[str]) -> OAuthToken:
        with self.store.connection() as connection:
            connection.execute("update refresh_tokens set revoked = 1 where token_hash = ?", (digest(refresh_token.token),))
        return self.issue_tokens(client.client_id or "", scopes, refresh_token.subject or "admin", None)

    async def load_access_token(self, token: str) -> AccessToken | None:
        with self.store.connection() as connection:
            row = connection.execute("select * from access_tokens where token_hash = ? and revoked = 0", (digest(token),)).fetchone()
        if not row:
            return None
        return AccessToken(token=token, client_id=row["client_id"], scopes=json.loads(row["scopes"]), subject=row["subject"], resource=row["resource"], expires_at=row["expires_at"])

    async def revoke_token(self, token: AccessToken | RefreshToken) -> None:
        token_hash = digest(token.token)
        with self.store.connection() as connection:
            connection.execute("update access_tokens set revoked = 1 where token_hash = ?", (token_hash,))
            connection.execute("update refresh_tokens set revoked = 1 where token_hash = ?", (token_hash,))

    def issue_code(self, request_id: str) -> tuple[str, str] | None:
        self.store.cleanup()
        with self.store.connection() as connection:
            row = connection.execute("select * from pending where id = ?", (request_id,)).fetchone()
            state_row = connection.execute("select state from oauth_states where request_id = ?", (request_id,)).fetchone()
            if not row or row["expires_at"] < int(time.time()):
                return None
            code = secrets.token_urlsafe(32)
            connection.execute("delete from pending where id = ?", (request_id,))
            connection.execute("delete from oauth_states where request_id = ?", (request_id,))
            connection.execute(
                "insert into codes values (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                (digest(code), row["client_id"], row["scopes"], row["code_challenge"], row["redirect_uri"], row["redirect_uri_explicit"], row["resource"], "balanzapro-vps-admin", int(time.time()) + 300),
            )
        parsed = urlparse(row["redirect_uri"])
        query = parse_qs(parsed.query)
        query["code"] = [code]
        if state_row and state_row["state"] is not None:
            query["state"] = [state_row["state"]]
        return urlunparse(parsed._replace(query=urlencode(query, doseq=True))), code

    def issue_tokens(self, client_id: str, scopes: list[str], subject: str, resource: str | None) -> OAuthToken:
        self.store.cleanup()
        access_token = secrets.token_urlsafe(48)
        refresh_token = secrets.token_urlsafe(48)
        now = int(time.time())
        with self.store.connection() as connection:
            connection.execute("insert into access_tokens values (?, ?, ?, ?, ?, ?, 0)", (digest(access_token), client_id, json.dumps(scopes), resource, subject, now + ACCESS_TOKEN_SECONDS))
            connection.execute("insert into refresh_tokens values (?, ?, ?, ?, ?, 0)", (digest(refresh_token), client_id, json.dumps(scopes), subject, now + REFRESH_TOKEN_SECONDS))
        return OAuthToken(access_token=access_token, refresh_token=refresh_token, expires_in=ACCESS_TOKEN_SECONDS, scope=" ".join(scopes))


def create_mcp() -> FastMCP:
    settings = load_settings()
    store = Store(settings.state_db)
    provider = OAuthProvider(settings, store)
    mcp = FastMCP(
        "BalanzaPro VPS Admin",
        instructions="Administracion total del VPS BalanzaPro. Ejecuta cambios solo cuando sean necesarios y verifica el resultado.",
        host=settings.bind,
        port=settings.port,
        streamable_http_path="/mcp",
        stateless_http=True,
        auth_server_provider=provider,
        auth=AuthSettings(
            issuer_url=settings.issuer_url,
            resource_server_url=settings.resource_url,
            required_scopes=[ADMIN_SCOPE],
            client_registration_options=ClientRegistrationOptions(enabled=True, valid_scopes=[ADMIN_SCOPE], default_scopes=[ADMIN_SCOPE]),
            revocation_options=RevocationOptions(enabled=True),
        ),
    )

    @mcp.custom_route("/health", methods=["GET"], include_in_schema=False)
    async def health(_: Request) -> PlainTextResponse:
        return PlainTextResponse("ok\n")

    @mcp.custom_route("/oauth/login", methods=["GET", "POST"], include_in_schema=False)
    async def login(request: Request):
        if request.method == "GET":
            request_id = request.query_params.get("request", "")
            body = f"""<!doctype html><html><head><meta charset=\"utf-8\"><title>BalanzaPro VPS MCP</title></head><body><h1>BalanzaPro VPS MCP</h1><p>Autoriza acceso administrativo total al VPS.</p><form method=\"post\"><input type=\"hidden\" name=\"request\" value=\"{html.escape(request_id)}\"><label>Contraseña administrativa <input type=\"password\" name=\"password\" autofocus required></label><button type=\"submit\">Autorizar</button></form></body></html>"""
            return HTMLResponse(body, headers={"Cache-Control": "no-store"})
        form = parse_qs((await request.body()).decode("utf-8", errors="replace"))
        request_id = form.get("request", [""])[0]
        password = form.get("password", [""])[0]
        if not hmac.compare_digest(digest(password), settings.password_hash):
            return PlainTextResponse("Contraseña inválida.", status_code=401, headers={"Cache-Control": "no-store"})
        result = provider.issue_code(request_id)
        if not result:
            return PlainTextResponse("La solicitud de autorización expiró o es inválida.", status_code=400, headers={"Cache-Control": "no-store"})
        return RedirectResponse(result[0], status_code=303, headers={"Cache-Control": "no-store"})

    @mcp.tool()
    def vps_shell(command: str, cwd: str = "/root", timeout_seconds: int = 300) -> dict[str, Any]:
        """Ejecuta cualquier comando shell como root en el VPS completo."""
        directory = Path(cwd).resolve()
        if not directory.is_dir():
            raise ValueError("cwd debe ser un directorio existente.")
        result = subprocess.run(command, cwd=directory, shell=True, capture_output=True, text=True, timeout=max(1, min(int(timeout_seconds), 3600)))
        return {"exit_code": result.returncode, "stdout": result.stdout[-30000:], "stderr": result.stderr[-15000:]}

    @mcp.tool()
    def vps_read_file(path: str, max_bytes: int = 1_000_000) -> dict[str, Any]:
        """Lee cualquier archivo de texto del VPS como root."""
        target = Path(path).resolve()
        if not target.is_file():
            raise FileNotFoundError(str(target))
        limit = max(1, min(int(max_bytes), 2_000_000))
        data = target.read_bytes()[:limit]
        return {"path": str(target), "size": target.stat().st_size, "truncated": target.stat().st_size > limit, "content": data.decode("utf-8", errors="replace")}

    @mcp.tool()
    def vps_write_file(path: str, content: str) -> dict[str, Any]:
        """Escribe cualquier archivo de texto del VPS como root."""
        target = Path(path).resolve()
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content, encoding="utf-8", newline="\n")
        return {"path": str(target), "bytes": len(content.encode())}

    return mcp


def main() -> None:
    create_mcp().run(transport="streamable-http")


if __name__ == "__main__":
    main()
