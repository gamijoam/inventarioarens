package storage_test

import (
	"context"
	"testing"
	"time"

	"inventarioarens/sync-daemon/pkg/client"
	"inventarioarens/sync-daemon/pkg/storage"
)

func setupTestDB(t *testing.T) *storage.DB {
	db, err := storage.Open(":memory:")
	if err != nil {
		t.Fatalf("failed to open in-memory sqlite: %v", err)
	}

	// Crear schema simplificado para pruebas
	schema := `
	CREATE TABLE tenants (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		name TEXT NOT NULL,
		slug TEXT NOT NULL UNIQUE
	);

	CREATE TABLE sync_nodes (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		tenant_id INTEGER NOT NULL,
		code TEXT NOT NULL,
		name TEXT NOT NULL,
		type TEXT DEFAULT 'local',
		status TEXT DEFAULT 'active'
	);

	CREATE TABLE sync_outbox (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		tenant_id INTEGER NOT NULL,
		event_uuid TEXT NOT NULL UNIQUE,
		event_type TEXT NOT NULL,
		aggregate_type TEXT NOT NULL,
		aggregate_id INTEGER,
		payload TEXT NOT NULL,
		occurred_at DATETIME NOT NULL,
		available_at DATETIME,
		status TEXT DEFAULT 'pending',
		processed_at DATETIME,
		last_error TEXT
	);

	CREATE TABLE sync_inbox (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		tenant_id INTEGER NOT NULL,
		origin_node_id INTEGER,
		event_uuid TEXT NOT NULL UNIQUE,
		event_type TEXT NOT NULL,
		aggregate_type TEXT NOT NULL,
		aggregate_id INTEGER,
		payload TEXT NOT NULL,
		status TEXT DEFAULT 'received',
		received_at DATETIME NOT NULL,
		applied_at DATETIME,
		last_error TEXT
	);
	`
	if err := db.Exec(schema); err != nil {
		t.Fatalf("failed to initialize test schema: %v", err)
	}

	return db
}

func TestStorage_GetTenantID(t *testing.T) {
	db := setupTestDB(t)
	defer db.Close()

	if err := db.Exec("INSERT INTO tenants (id, name, slug) VALUES (4, 'Asia Moto', 'asiamoto')"); err != nil {
		t.Fatalf("failed to insert tenant: %v", err)
	}

	ctx := context.Background()
	tenantID, err := db.GetTenantID(ctx, "asiamoto")
	if err != nil {
		t.Fatalf("expected tenant, got error: %v", err)
	}
	if tenantID != 4 {
		t.Errorf("expected tenantID 4, got %d", tenantID)
	}

	_, err = db.GetTenantID(ctx, "non-existent")
	if err == nil {
		t.Errorf("expected error for non-existent tenant, got nil")
	}
}

func TestStorage_OutboxFlow(t *testing.T) {
	db := setupTestDB(t)
	defer db.Close()

	ctx := context.Background()

	// Insertar 2 eventos pendientes y 1 procesado
	_ = db.Exec(`
		INSERT INTO sync_outbox (id, tenant_id, event_uuid, event_type, aggregate_type, aggregate_id, payload, occurred_at, status)
		VALUES 
		(1, 4, 'uuid-1', 'sale.created', 'sale', 10, '{"total":100}', '2026-09-27 10:00:00', 'pending'),
		(2, 4, 'uuid-2', 'sale.created', 'sale', 11, '{"total":200}', '2026-09-27 10:05:00', 'pending'),
		(3, 4, 'uuid-3', 'sale.created', 'sale', 12, '{"total":300}', '2026-09-27 10:10:00', 'processed');
	`)

	events, ids, err := db.GetPendingOutbox(ctx, 4, 10)
	if err != nil {
		t.Fatalf("failed to get pending outbox: %v", err)
	}

	if len(events) != 2 {
		t.Fatalf("expected 2 pending events, got %d", len(events))
	}
	if events[0].EventUUID != "uuid-1" || events[1].EventUUID != "uuid-2" {
		t.Errorf("unexpected events: %+v", events)
	}
	if len(ids) != 2 || ids[0] != 1 || ids[1] != 2 {
		t.Errorf("unexpected ids: %+v", ids)
	}

	// Marcar como procesados
	if err := db.MarkOutboxProcessed(ctx, 4, ids); err != nil {
		t.Fatalf("failed to mark processed: %v", err)
	}

	// Ahora no deben quedar eventos pendientes
	remaining, _, _ := db.GetPendingOutbox(ctx, 4, 10)
	if len(remaining) != 0 {
		t.Errorf("expected 0 pending events after mark processed, got %d", len(remaining))
	}
}

func TestStorage_InboxStore(t *testing.T) {
	db := setupTestDB(t)
	defer db.Close()

	ctx := context.Background()

	inboxEvents := []client.InboxEvent{
		{
			EventUUID:     "cloud-e1",
			EventType:     "product.created",
			AggregateType: "product",
			AggregateID:   "500",
			Payload:       map[string]interface{}{"name": "Aceite"},
			OccurredAt:    time.Now().Format(time.RFC3339),
		},
		{
			EventUUID:     "cloud-e2",
			EventType:     "price.updated",
			AggregateType: "price",
			AggregateID:   "500",
			Payload:       map[string]interface{}{"price": 10.0},
			OccurredAt:    time.Now().Format(time.RFC3339),
		},
	}

	stored, err := db.StoreInboxEvents(ctx, 4, 1, inboxEvents)
	if err != nil {
		t.Fatalf("failed to store inbox events: %v", err)
	}
	if stored != 2 {
		t.Errorf("expected 2 stored events, got %d", stored)
	}

	// Al intentar almacenar los mismos, no debe duplicar (INSERT OR IGNORE)
	storedAgain, err := db.StoreInboxEvents(ctx, 4, 1, inboxEvents)
	if err != nil {
		t.Fatalf("failed on second store: %v", err)
	}
	if storedAgain != 0 {
		t.Errorf("expected 0 stored on duplicate, got %d", storedAgain)
	}

	// Contar pendientes
	count, err := db.CountPendingInbox(ctx, 4)
	if err != nil {
		t.Fatalf("failed to count pending inbox: %v", err)
	}
	if count != 2 {
		t.Errorf("expected 2 pending inbox events, got %d", count)
	}
}
