#!/usr/bin/env python3
import psycopg2
from psycopg2.extras import RealDictCursor
import sys

try:
    conn = psycopg2.connect(host="db_qa_server", port=5432, dbname="invensoft_qa", user="postgres", password="postgres", connect_timeout=5)
    cur = conn.cursor(cursor_factory=RealDictCursor)

    print("=== COLUMNAS DE inventory_transfers EN QA ===")
    cur.execute("SELECT column_name, data_type FROM information_schema.columns WHERE table_name='inventory_transfers' ORDER BY ordinal_position")
    for r in cur.fetchall():
        print(f"  {r['column_name']} ({r['data_type']})")

    print()
    print("=== DATA inventory_transfers EN QA (top 10) ===")
    cur.execute("SELECT * FROM inventory_transfers ORDER BY id DESC LIMIT 10")
    for r in cur.fetchall():
        print(f"  {dict(r)}")

    print()
    print("=== COLUMNAS DE inter_company_transfers EN QA ===")
    cur.execute("SELECT column_name, data_type FROM information_schema.columns WHERE table_name='inter_company_transfers' ORDER BY ordinal_position")
    for r in cur.fetchall():
        print(f"  {r['column_name']} ({r['data_type']})")

    print()
    print("=== DATA inter_company_transfers EN QA (top 5) ===")
    cur.execute("SELECT * FROM inter_company_transfers ORDER BY id DESC LIMIT 5")
    for r in cur.fetchall():
        print(f"  {dict(r)}")

    print()
    print("=== TENANTS EN QA ===")
    cur.execute("SELECT column_name FROM information_schema.columns WHERE table_name='tenants' ORDER BY ordinal_position")
    print("  columns: " + ", ".join([r['column_name'] for r in cur.fetchall()]))
    cur.execute("SELECT * FROM tenants ORDER BY id LIMIT 5")
    for r in cur.fetchall():
        print(f"  {dict(r)}")

    print()
    print("=== TABLAS CON 'sync' EN QA ===")
    cur.execute("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_name LIKE '%sync%' ORDER BY table_name")
    for r in cur.fetchall():
        print(f"  {r['table_name']}")

    print()
    print("=== TABLAS CON 'outbox' o 'inbox' ===")
    cur.execute("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND (table_name LIKE '%outbox%' OR table_name LIKE '%inbox%') ORDER BY table_name")
    for r in cur.fetchall():
        print(f"  {r['table_name']}")

    conn.close()
except Exception as e:
    print(f"ERROR: {e}")
    import traceback
    traceback.print_exc()
    sys.exit(1)
