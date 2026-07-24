#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Remote MCP server for ChatGPT/Codex access to INVENTARIOARENS.

This server is intentionally scoped to one Laravel project and one PostgreSQL
database. It exposes technical tools for reading project files, applying small
patches, and running read-only SQL diagnostics against inventory_arens.
"""
from __future__ import annotations

import difflib
import os
import re
import subprocess
from dataclasses import dataclass
from pathlib import Path
from typing import Any


DEFAULT_PROJECT_ROOT = "/opt/inventarioarens-cloud"
MAX_READ_BYTES = 200_000
MAX_SQL_LIMIT = 200

SAFE_PATH_RE = re.compile(r"^[A-Za-z0-9_./@+,\- ]+$")
TENANT_RE = re.compile(r"^[a-z0-9][a-z0-9_-]{0,80}$")
BLOCKED_DIRS = {
    ".git",
    "vendor",
    "node_modules",
    "storage/framework/cache",
    "storage/framework/sessions",
    "storage/framework/views",
}
WRITE_DENY_PREFIXES = (
    ".env",
    "storage/",
    "vendor/",
    "node_modules/",
    ".git/",
)


@dataclass(frozen=True)
class Settings:
    project_root: Path
    access_key: str
    db_host: str
    db_port: int
    db_name: str
    db_user: str
    db_password: str
    host: str
    port: int


def load_settings() -> Settings:
    return Settings(
        project_root=Path(os.getenv("INVENTARIOARENS_PROJECT_ROOT", DEFAULT_PROJECT_ROOT)).resolve(),
        access_key=os.getenv("INVENTARIOARENS_MCP_ACCESS_KEY", ""),
        db_host=os.getenv("INVENTARIOARENS_DB_HOST", "127.0.0.1"),
        db_port=int(os.getenv("INVENTARIOARENS_DB_PORT", "5432")),
        db_name=os.getenv("INVENTARIOARENS_DB_NAME", "inventory_arens"),
        db_user=os.getenv("INVENTARIOARENS_DB_USER", "postgres"),
        db_password=os.getenv("INVENTARIOARENS_DB_PASSWORD", ""),
        host=os.getenv("INVENTARIOARENS_MCP_HOST", "127.0.0.1"),
        port=int(os.getenv("INVENTARIOARENS_MCP_PORT", "17888")),
    )


def require_access(settings: Settings, access_key: str) -> None:
    if not settings.access_key:
        raise PermissionError("INVENTARIOARENS_MCP_ACCESS_KEY no esta configurado.")
    if access_key != settings.access_key:
        raise PermissionError("access_key invalido.")


def validate_tenant_slug(tenant_slug: str) -> str:
    if not TENANT_RE.match(tenant_slug):
        raise ValueError("tenant_slug invalido.")
    return tenant_slug


def resolve_project_path(settings: Settings, relative_path: str, *, writable: bool = False) -> Path:
    if not relative_path or relative_path.startswith(("/", "\\")):
        raise ValueError("Usa una ruta relativa dentro del proyecto.")
    normalized = relative_path.replace("\\", "/").strip()
    if not SAFE_PATH_RE.match(normalized):
        raise ValueError("La ruta contiene caracteres no permitidos.")
    parts = [part for part in normalized.split("/") if part not in ("", ".")]
    if any(part == ".." for part in parts):
        raise ValueError("No se permite subir de directorio.")
    compact = "/".join(parts)
    if any(compact == blocked or compact.startswith(blocked + "/") for blocked in BLOCKED_DIRS):
        raise ValueError("Ruta bloqueada para MCP.")
    if writable and any(compact == denied.rstrip("/") or compact.startswith(denied) for denied in WRITE_DENY_PREFIXES):
        raise ValueError("Ruta no escribible por MCP.")
    path = (settings.project_root / compact).resolve()
    if settings.project_root not in (path, *path.parents):
        raise ValueError("La ruta salio del proyecto permitido.")
    return path


def is_select_sql(sql: str) -> bool:
    stripped = re.sub(r"/\*.*?\*/", "", sql, flags=re.S).strip().lower()
    stripped = re.sub(r"--.*?$", "", stripped, flags=re.M).strip()
    return stripped.startswith(("select ", "with ")) and ";" not in stripped.rstrip(";")


def clamp_limit(limit: int) -> int:
    return max(1, min(int(limit), MAX_SQL_LIMIT))


def connect_db(settings: Settings):
    import psycopg
    from psycopg.rows import dict_row

    return psycopg.connect(
        host=settings.db_host,
        port=settings.db_port,
        dbname=settings.db_name,
        user=settings.db_user,
        password=settings.db_password,
        row_factory=dict_row,
    )


def query_dicts(settings: Settings, sql: str, params: tuple[Any, ...] = ()) -> list[dict[str, Any]]:
    with connect_db(settings) as conn:
        conn.execute("SET default_transaction_read_only = on")
        with conn.cursor() as cur:
            cur.execute(sql, params)
            return [dict(row) for row in cur.fetchall()]


def tenant_id(settings: Settings, tenant_slug: str) -> int:
    slug = validate_tenant_slug(tenant_slug)
    rows = query_dicts(settings, "select id from tenants where slug = %s limit 1", (slug,))
    if not rows:
        raise ValueError(f"No existe tenant con slug '{slug}'.")
    return int(rows[0]["id"])


def create_mcp():
    from mcp.server.fastmcp import FastMCP

    settings = load_settings()
    mcp = FastMCP(
        "InventarioArens MCP",
        instructions=(
            "Herramientas tecnicas para INVENTARIOARENS. "
            "Usar solo sobre el proyecto /opt/inventarioarens-cloud y la BD inventory_arens."
        ),
        host=settings.host,
        port=settings.port,
    )

    @mcp.tool()
    def project_status(access_key: str) -> dict[str, Any]:
        """Resumen tecnico del proyecto, git, Laravel y rutas principales."""
        require_access(settings, access_key)
        root = settings.project_root
        artisan = root / "artisan"
        git_head = subprocess.run(
            ["git", "-C", str(root), "rev-parse", "--short", "HEAD"],
            capture_output=True,
            text=True,
            timeout=10,
        )
        git_status = subprocess.run(
            ["git", "-C", str(root), "status", "--short"],
            capture_output=True,
            text=True,
            timeout=10,
        )
        return {
            "project_root": str(root),
            "artisan_exists": artisan.exists(),
            "git_head": git_head.stdout.strip() if git_head.returncode == 0 else None,
            "git_dirty_files": git_status.stdout.splitlines()[:80] if git_status.returncode == 0 else [],
            "database": settings.db_name,
        }

    @mcp.tool()
    def list_project_files(access_key: str, path: str = ".", limit: int = 100) -> dict[str, Any]:
        """Lista archivos/directorios dentro del proyecto permitido."""
        require_access(settings, access_key)
        base = resolve_project_path(settings, path)
        items: list[dict[str, Any]] = []
        for child in sorted(base.iterdir(), key=lambda p: (not p.is_dir(), p.name.lower()))[:clamp_limit(limit)]:
            rel = child.relative_to(settings.project_root).as_posix()
            if any(rel == blocked or rel.startswith(blocked + "/") for blocked in BLOCKED_DIRS):
                continue
            items.append({"path": rel, "type": "dir" if child.is_dir() else "file", "size": child.stat().st_size})
        return {"base": base.relative_to(settings.project_root).as_posix(), "items": items}

    @mcp.tool()
    def read_project_file(access_key: str, path: str, max_bytes: int = 60000) -> dict[str, Any]:
        """Lee un archivo de texto dentro del proyecto."""
        require_access(settings, access_key)
        target = resolve_project_path(settings, path)
        if not target.is_file():
            raise FileNotFoundError(path)
        size = target.stat().st_size
        read_size = min(max(1, int(max_bytes)), MAX_READ_BYTES)
        data = target.read_bytes()[:read_size]
        return {
            "path": target.relative_to(settings.project_root).as_posix(),
            "size": size,
            "truncated": size > read_size,
            "content": data.decode("utf-8", errors="replace"),
        }

    @mcp.tool()
    def search_project(access_key: str, pattern: str, path: str = ".", limit: int = 100) -> dict[str, Any]:
        """Busca texto con ripgrep dentro del proyecto."""
        require_access(settings, access_key)
        base = resolve_project_path(settings, path)
        result = subprocess.run(
            ["rg", "--line-number", "--no-heading", "--color", "never", "-m", "3", pattern, str(base)],
            capture_output=True,
            text=True,
            timeout=20,
        )
        lines = result.stdout.splitlines()[:clamp_limit(limit)]
        return {"matches": lines, "truncated": len(result.stdout.splitlines()) > len(lines)}

    @mcp.tool()
    def write_project_file(access_key: str, path: str, content: str, expected_current: str = "") -> dict[str, Any]:
        """
        Escribe un archivo de texto dentro del proyecto.

        Si el archivo existe, expected_current debe coincidir con el contenido actual
        para evitar pisar cambios no revisados.
        """
        require_access(settings, access_key)
        target = resolve_project_path(settings, path, writable=True)
        current = target.read_text(encoding="utf-8") if target.exists() else ""
        if target.exists() and expected_current != current:
            diff = "\n".join(difflib.unified_diff(
                expected_current.splitlines(),
                current.splitlines(),
                fromfile="expected",
                tofile="current",
                lineterm="",
            )[:200])
            raise RuntimeError("expected_current no coincide con el archivo actual.\n" + diff)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content, encoding="utf-8", newline="\n")
        return {"path": target.relative_to(settings.project_root).as_posix(), "bytes": len(content.encode("utf-8"))}

    @mcp.tool()
    def db_select(access_key: str, sql: str, limit: int = 100) -> dict[str, Any]:
        """Ejecuta SQL de solo lectura sobre inventory_arens. Solo SELECT/WITH, sin punto y coma."""
        require_access(settings, access_key)
        if not is_select_sql(sql):
            raise ValueError("Solo se permite SELECT/WITH de lectura y sin multiples sentencias.")
        rows = query_dicts(settings, f"select * from ({sql}) as mcp_query limit %s", (clamp_limit(limit),))
        return {"rows": rows, "limit": clamp_limit(limit), "truncated_possible": len(rows) == clamp_limit(limit)}

    @mcp.tool()
    def tenant_overview(access_key: str, tenant_slug: str) -> dict[str, Any]:
        """Resumen operativo de una empresa/tenant."""
        require_access(settings, access_key)
        tid = tenant_id(settings, tenant_slug)
        rows = query_dicts(
            settings,
            """
            select
                (select count(*) from products where tenant_id = %s) as products,
                (select count(*) from users u join tenant_user tu on tu.user_id = u.id where tu.tenant_id = %s) as users,
                (select count(*) from warehouses where tenant_id = %s) as warehouses,
                (select count(*) from cash_register_sessions where tenant_id = %s and status = 'open') as open_cash_sessions,
                (select count(*) from sync_outbox where tenant_id = %s and status = 'pending') as outbox_pending,
                (select count(*) from sync_inbox where tenant_id = %s and status = 'failed') as inbox_failed
            """,
            (tid, tid, tid, tid, tid, tid),
        )
        return {"tenant_slug": tenant_slug, **rows[0]}

    @mcp.tool()
    def search_products(access_key: str, tenant_slug: str, query: str, limit: int = 20) -> dict[str, Any]:
        """Busca productos por nombre, SKU o codigo de barras."""
        require_access(settings, access_key)
        tid = tenant_id(settings, tenant_slug)
        like = f"%{query}%"
        rows = query_dicts(
            settings,
            """
            select p.id, p.sku, p.name, p.barcode, p.base_price, p.tracking_type, p.status,
                   coalesce(sum(sb.quantity_available), 0) as available
            from products p
            left join stock_balances sb on sb.tenant_id = p.tenant_id and sb.product_id = p.id
            where p.tenant_id = %s
              and (p.name ilike %s or p.sku ilike %s or p.barcode ilike %s)
            group by p.id
            order by p.name
            limit %s
            """,
            (tid, like, like, like, clamp_limit(limit)),
        )
        return {"tenant_slug": tenant_slug, "products": rows}

    @mcp.tool()
    def sync_overview(access_key: str, tenant_slug: str) -> dict[str, Any]:
        """Estado resumido de outbox/inbox del tenant."""
        require_access(settings, access_key)
        tid = tenant_id(settings, tenant_slug)
        rows = query_dicts(
            settings,
            """
            select 'outbox' as box, status, count(*) as total from sync_outbox where tenant_id = %s group by status
            union all
            select 'inbox' as box, status, count(*) as total from sync_inbox where tenant_id = %s group by status
            order by box, status
            """,
            (tid, tid),
        )
        return {"tenant_slug": tenant_slug, "statuses": rows}

    return mcp


def main() -> None:
    create_mcp().run(transport="sse")


if __name__ == "__main__":
    main()
