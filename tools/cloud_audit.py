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

    print("=== TODOS LOS inventory_transfers EN LA NUBE ===")
    cur.execute("SELECT id, tenant_id, document_number, status, resolution_status, from_warehouse_id, to_warehouse_id, processed_at, dispatched_at, created_at FROM inventory_transfers ORDER BY id")
    for r in cur.fetchall():
        print(f"  #{r['id']}  tenant={r['tenant_id']}  {r['document_number']}  status={r['status']}  res={r['resolution_status']}  from_wh={r['from_warehouse_id']}  to_wh={r['to_warehouse_id']}  created={r['created_at']}")

    print()
    print("=== ITEMS DE LOS TRF-* ===")
    cur.execute("SELECT id, inventory_transfer_id, product_id, quantity, requested_quantity, prepared_quantity, received_quantity FROM inventory_transfer_items WHERE inventory_transfer_id IN (SELECT id FROM inventory_transfers WHERE document_number LIKE 'TRF-%') ORDER BY inventory_transfer_id, id")
    for r in cur.fetchall():
        print(f"  item_id={r['id']}  transfer={r['inventory_transfer_id']}  product={r['product_id']}  qty={r['quantity']}  prep={r['prepared_quantity']}  recv={r['received_quantity']}")

    print()
    print("=== SYNC_INBOX (inventory_transfer.*) ===")
    cur.execute("SELECT id, event_type, aggregate_type, aggregate_id, status, last_error, created_at FROM sync_inbox WHERE event_type LIKE 'inventory_transfer%' ORDER BY id DESC LIMIT 20")
    rows = cur.fetchall()
    print(f"Total: {len(rows)}")
    for r in rows:
        err = (r['last_error'] or '')[:120]
        print(f"  #{r['id']}  {r['event_type']}  aggr={r['aggregate_id']}  status={r['status']}  err={err}  created={r['created_at']}")

    print()
    print("=== SYNC_INBOX count por status (inventory_transfer.*) ===")
    cur.execute("SELECT status, COUNT(*) as c FROM sync_inbox WHERE event_type LIKE 'inventory_transfer%' GROUP BY status")
    for r in cur.fetchall():
        print(f"  {r['status']} = {r['c']}")

    print()
    print("=== SYNC_OUTBOX (inventory_transfer.*) ===")
    cur.execute("SELECT id, event_type, aggregate_id, status, last_error, available_at, updated_at, target_node_id, origin_node_id FROM sync_outbox WHERE event_type LIKE 'inventory_transfer%' ORDER BY id DESC LIMIT 20")
    rows = cur.fetchall()
    print(f"Total: {len(rows)}")
    for r in rows:
        err = (r['last_error'] or '')[:80]
        print(f"  #{r['id']}  {r['event_type']}  aggr={r['aggregate_id']}  origin={r['origin_node_id']}  target={r['target_node_id']}  status={r['status']}  err={err}  upd={r['updated_at']}")

    print()
    print("=== SYNC_OUTBOX count por status (inventory_transfer.*) ===")
    cur.execute("SELECT status, COUNT(*) as c FROM sync_outbox WHERE event_type LIKE 'inventory_transfer%' GROUP BY status")
    for r in cur.fetchall():
        print(f"  {r['status']} = {r['c']}")

    print()
    print("=== TENANTS en la nube ===")
    cur.execute("SELECT id, slug, name FROM tenants ORDER BY id")
    for r in cur.fetchall():
        print(f"  #{r['id']}  {r['slug']}  {r['name']}")

    print()
    print("=== WAREHOUSES para tenant 2 (la nube) ===")
    cur.execute("SELECT id, code, name FROM warehouses WHERE tenant_id = 2 ORDER BY id")
    for r in cur.fetchall():
        print(f"  #{r['id']}  code={r['code']}  name={r['name']}")

    print()
    print("=== NODOS ACTIVOS en la nube ===")
    cur.execute("SELECT id, tenant_id, code, type, status, last_seen_at FROM sync_nodes ORDER BY id")
    for r in cur.fetchall():
        print(f"  #{r['id']}  tenant={r['tenant_id']}  code={r['code']}  type={r['type']}  status={r['status']}  last_seen={r['last_seen_at']}")

    print()
    print("=== ULTIMO 8 EVENTOS EN OUTBOX DE LA NUBE (cualquier tipo) ===")
    cur.execute("SELECT id, event_type, aggregate_id, status, target_node_id, origin_node_id, available_at, updated_at FROM sync_outbox ORDER BY id DESC LIMIT 8")
    for r in cur.fetchall():
        print(f"  #{r['id']}  {r['event_type']}  aggr={r['aggregate_id']}  origin={r['origin_node_id']}  target={r['target_node_id']}  status={r['status']}  avail={r['available_at']}  upd={r['updated_at']}")

    conn.close()
except Exception as e:
    print(f"ERROR: {e}")
    import traceback
    traceback.print_exc()
    sys.exit(1)
