#!/usr/bin/env python3
import psycopg2
from psycopg2.extras import RealDictCursor
import os, sys

try:
    conn = psycopg2.connect(
        host="db_prod",
        port=5432,
        dbname="invensoft_prod",
        user="postgres",
        password="GaboMac12",
    )
    cur = conn.cursor(cursor_factory=RealDictCursor)

    print("=== TODAS LAS TABLAS EN LA NUBE ===")
    cur.execute("SELECT table_name FROM information_schema.tables WHERE table_schema='public' ORDER BY table_name")
    tables = [r['table_name'] for r in cur.fetchall()]
    for t in tables:
        print(f"  {t}")
    print(f"\nTotal: {len(tables)} tablas")

    print()
    print("=== TABLAS QUE CONTIENEN 'transfer' ===")
    transfer_tables = [t for t in tables if 'transfer' in t.lower()]
    for t in transfer_tables:
        print(f"  {t}")

    print()
    print("=== TABLAS QUE CONTIENEN 'sync' ===")
    sync_tables = [t for t in tables if 'sync' in t.lower()]
    for t in sync_tables:
        print(f"  {t}")

    print()
    print("=== TENANTS en la nube ===")
    cur.execute("SELECT id, slug, name FROM tenants ORDER BY id")
    for r in cur.fetchall():
        print(f"  #{r['id']}  {r['slug']}  {r['name']}")

    print()
    print("=== BUSCAR 'inventory_transfers' EN TODOS LOS SCHEMAS ===")
    cur.execute("SELECT table_schema, table_name FROM information_schema.tables WHERE table_name LIKE 'inventory_transfer%'")
    for r in cur.fetchall():
        print(f"  schema={r['table_schema']}  table={r['table_name']}")

    conn.close()
except Exception as e:
    print(f"ERROR: {e}")
    import traceback
    traceback.print_exc()
    sys.exit(1)
