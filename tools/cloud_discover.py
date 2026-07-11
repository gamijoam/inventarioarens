#!/usr/bin/env python3
import psycopg2
from psycopg2.extras import RealDictCursor
import os, sys

def try_db(host, port, dbname, user, password, label):
    print(f"\n========== TRYING {label}: {host}:{port}/{dbname} as {user} ==========")
    try:
        conn = psycopg2.connect(
            host=host, port=port, dbname=dbname, user=user, password=password,
            connect_timeout=5,
        )
        cur = conn.cursor(cursor_factory=RealDictCursor)
        cur.execute("SELECT table_name FROM information_schema.tables WHERE table_schema='public' ORDER BY table_name")
        tables = [r['table_name'] for r in cur.fetchall()]
        has_inv = [t for t in tables if 'inventory' in t.lower() or 'sync' in t.lower() or 'transfer' in t.lower()]
        print(f"  OK. Total tables: {len(tables)}. Relevant: {has_inv}")
        if has_inv:
            for t in has_inv:
                print(f"    - {t}")
            # Check inventory_transfers
            try:
                cur.execute("SELECT id, tenant_id, document_number, status FROM inventory_transfers ORDER BY id DESC LIMIT 10")
                print(f"  --- inventory_transfers (top 10) ---")
                for r in cur.fetchall():
                    print(f"    #{r['id']}  tenant={r['tenant_id']}  {r['document_number']}  status={r['status']}")
            except Exception as e:
                print(f"  Cannot query inventory_transfers: {e}")
            try:
                cur.execute("SELECT id, event_type, aggregate_id, status, last_error FROM sync_outbox WHERE event_type LIKE 'inventory_transfer%' ORDER BY id DESC LIMIT 10")
                print(f"  --- sync_outbox inventory_transfer (top 10) ---")
                for r in cur.fetchall():
                    print(f"    #{r['id']}  {r['event_type']}  aggr={r['aggregate_id']}  status={r['status']}  err={(r['last_error'] or '')[:60]}")
            except Exception as e:
                print(f"  Cannot query sync_outbox: {e}")
            try:
                cur.execute("SELECT id, event_type, aggregate_id, status, last_error FROM sync_inbox WHERE event_type LIKE 'inventory_transfer%' ORDER BY id DESC LIMIT 10")
                print(f"  --- sync_inbox inventory_transfer (top 10) ---")
                for r in cur.fetchall():
                    print(f"    #{r['id']}  {r['event_type']}  aggr={r['aggregate_id']}  status={r['status']}  err={(r['last_error'] or '')[:60]}")
            except Exception as e:
                print(f"  Cannot query sync_inbox: {e}")
        conn.close()
    except Exception as e:
        print(f"  FAILED: {e}")

# Try different databases
try_db("db_qa_server", 5432, "invensoft_qa", "postgres", "postgres", "QA - invensoft_qa (postgres/postgres)")
try_db("db_qa_server", 5432, "invensoft_qa", "invensoft", "invensoft", "QA - invensoft_qa (invensoft/invensoft)")
try_db("db_prod_server", 5432, "invensoft_prod", "postgres", "GaboMac12", "PROD - invensoft_prod (postgres/GaboMac12)")
try_db("db_prod_server", 5432, "invensoft", "postgres", "GaboMac12", "PROD - invensoft (postgres/GaboMac12)")
