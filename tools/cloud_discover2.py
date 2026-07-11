#!/usr/bin/env python3
import psycopg2
from psycopg2.extras import RealDictCursor
import os, sys

def try_db(host, port, dbname, user, password, label):
    print(f"\n========== {label}: {host}:{port}/{dbname} as {user} ==========")
    try:
        conn = psycopg2.connect(host=host, port=port, dbname=dbname, user=user, password=password, connect_timeout=5)
        cur = conn.cursor(cursor_factory=RealDictCursor)
        cur.execute("SELECT table_name FROM information_schema.tables WHERE table_schema='public' ORDER BY table_name")
        tables = [r['table_name'] for r in cur.fetchall()]
        has_inv = [t for t in tables if 'inventory' in t.lower() or 'sync' in t.lower() or 'transfer' in t.lower()]
        print(f"  OK. Total tables: {len(tables)}. Relevant: {has_inv}")
        if 'inventory_transfers' in tables:
            cur.execute("SELECT id, tenant_id, document_number, status, resolution_status, from_warehouse_id, to_warehouse_id, created_at FROM inventory_transfers ORDER BY id DESC LIMIT 20")
            print(f"  --- inventory_transfers (top 20) ---")
            for r in cur.fetchall():
                print(f"    #{r['id']}  tenant={r['tenant_id']}  {r['document_number']}  status={r['status']}  res={r['resolution_status']}  from_wh={r['from_warehouse_id']}  to_wh={r['to_warehouse_id']}  created={r['created_at']}")
        if 'sync_inbox' in tables:
            cur.execute("SELECT id, event_type, aggregate_id, status, last_error, created_at FROM sync_inbox WHERE event_type LIKE 'inventory_transfer%' ORDER BY id DESC LIMIT 20")
            print(f"  --- sync_inbox inventory_transfer.* (top 20) ---")
            for r in cur.fetchall():
                print(f"    #{r['id']}  {r['event_type']}  aggr={r['aggregate_id']}  status={r['status']}  err={(r['last_error'] or '')[:80]}  created={r['created_at']}")
        if 'sync_outbox' in tables:
            cur.execute("SELECT id, event_type, aggregate_id, status, last_error, target_node_id, origin_node_id, available_at, updated_at FROM sync_outbox WHERE event_type LIKE 'inventory_transfer%' ORDER BY id DESC LIMIT 20")
            print(f"  --- sync_outbox inventory_transfer.* (top 20) ---")
            for r in cur.fetchall():
                print(f"    #{r['id']}  {r['event_type']}  aggr={r['aggregate_id']}  origin={r['origin_node_id']}  target={r['target_node_id']}  status={r['status']}  err={(r['last_error'] or '')[:80]}")
            cur.execute("SELECT status, COUNT(*) as c FROM sync_outbox WHERE event_type LIKE 'inventory_transfer%' GROUP BY status")
            print(f"  --- sync_outbox status count ---")
            for r in cur.fetchall():
                print(f"    {r['status']} = {r['c']}")
        if 'tenants' in tables:
            cur.execute("SELECT column_name FROM information_schema.columns WHERE table_name='tenants' ORDER BY ordinal_position")
            print(f"  --- tenants columns ---")
            for r in cur.fetchall():
                print(f"    {r['column_name']}")
            cur.execute("SELECT * FROM tenants ORDER BY id LIMIT 10")
            print(f"  --- tenants data ---")
            for r in cur.fetchall():
                print(f"    {dict(r)}")
        conn.close()
    except Exception as e:
        print(f"  FAILED: {e}")

# QA
try_db("db_qa_server", 5432, "invensoft_qa", "postgres", "postgres", "QA invensoft_qa")

# Maybe the prod DB has been overwritten — check inventory_transfer-like tables
try:
    conn = psycopg2.connect(host="db_prod", port=5432, dbname="invensoft_prod", user="postgres", password="GaboMac12", connect_timeout=5)
    cur = conn.cursor(cursor_factory=RealDictCursor)
    cur.execute("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND (table_name LIKE '%sync%' OR table_name LIKE '%transf%' OR table_name LIKE '%product%' OR table_name LIKE '%warehouse%' OR table_name LIKE '%inventor%' OR table_name LIKE '%stock%' OR table_name LIKE '%price%' OR table_name LIKE '%customer%') ORDER BY table_name")
    print("\n========== PROD: tablas relevantes (productos, sync, transfers, etc) ==========")
    rows = cur.fetchall()
    for r in rows:
        print(f"  {r['table_name']}")
    if not rows:
        print("  (ninguna)")
    conn.close()
except Exception as e:
    print(f"PROD ERROR: {e}")
