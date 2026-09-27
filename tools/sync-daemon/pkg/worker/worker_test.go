package worker_test

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"inventarioarens/sync-daemon/pkg/client"
	"inventarioarens/sync-daemon/pkg/config"
	"inventarioarens/sync-daemon/pkg/storage"
	"inventarioarens/sync-daemon/pkg/worker"
)

func setupTestStorage(t *testing.T) *storage.DB {
	db, err := storage.Open(":memory:")
	if err != nil {
		t.Fatalf("failed to open test sqlite: %v", err)
	}

	schema := `
	CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT, slug TEXT UNIQUE);
	CREATE TABLE sync_nodes (id INTEGER PRIMARY KEY, tenant_id INTEGER, code TEXT, name TEXT, type TEXT, status TEXT);
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
	INSERT INTO tenants (id, name, slug) VALUES (4, 'Asia Moto', 'asiamoto');
	`
	if err := db.Exec(schema); err != nil {
		t.Fatalf("failed to create schema: %v", err)
	}
	return db
}

func TestWorker_FullSyncCycle(t *testing.T) {
	pushedCount := 0
	pulledCount := 0
	ackedCount := 0

	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")

		if r.URL.Path == "/sync/events/push" {
			var body map[string]interface{}
			_ = json.NewDecoder(r.Body).Decode(&body)
			events := body["events"].([]interface{})
			pushedCount += len(events)
			w.WriteHeader(http.StatusOK)
			_, _ = w.Write([]byte(`{"data": {"received": 1, "duplicated": 0}}`))
			return
		}

		if r.URL.Path == "/sync/events/pull" {
			pulledCount++
			w.WriteHeader(http.StatusOK)
			_, _ = w.Write([]byte(`{
				"data": [
					{
						"id": 10,
						"event_uuid": "cloud-e1",
						"event_type": "price.updated",
						"aggregate_type": "price",
						"aggregate_id": "99",
						"payload": {"price": 15.0},
						"occurred_at": "2026-09-27T10:00:00Z"
					}
				]
			}`))
			return
		}

		if r.URL.Path == "/sync/events/cloud-e1/ack" {
			ackedCount++
			w.WriteHeader(http.StatusOK)
			_, _ = w.Write([]byte(`{"ok": true}`))
			return
		}

		w.WriteHeader(http.StatusNotFound)
	}))
	defer ts.Close()

	db := setupTestStorage(t)
	defer db.Close()

	// Insertar 1 evento pendiente en outbox
	_ = db.Exec(`
		INSERT INTO sync_outbox (id, tenant_id, event_uuid, event_type, aggregate_type, aggregate_id, payload, occurred_at, status)
		VALUES (1, 4, 'local-e1', 'sale.created', 'sale', 1, '{"total": 50}', '2026-09-27 10:00:00', 'pending');
	`)

	c := client.New(client.Options{Timeout: 5 * time.Second})

	appliedCalled := false
	mockApplier := worker.ApplyFunc(func(ctx context.Context, tenantSlug string, limit int) (int, error) {
		appliedCalled = true
		if tenantSlug != "asiamoto" {
			t.Errorf("expected tenant slug asiamoto, got %s", tenantSlug)
		}
		return 1, nil
	})

	w := worker.New(db, c, mockApplier)

	cfg := config.TenantConfig{
		Token:            "secret-123",
		CloudURL:         ts.URL,
		NodeCode:         "LOCAL-01",
		NodeName:         "Caja 1",
		InstallationCode: "INSTALL-01",
		Limit:            50,
		Interval:         15,
	}

	summary, err := w.RunTenantSync(context.Background(), "asiamoto", cfg)
	if err != nil {
		t.Fatalf("sync cycle failed: %v", err)
	}

	if summary.Pushed != 1 {
		t.Errorf("expected 1 pushed, got %d", summary.Pushed)
	}
	if summary.Pulled != 1 {
		t.Errorf("expected 1 pulled, got %d", summary.Pulled)
	}
	if summary.Acked != 1 {
		t.Errorf("expected 1 acked, got %d", summary.Acked)
	}
	if summary.Applied != 1 {
		t.Errorf("expected 1 applied, got %d", summary.Applied)
	}
	if !appliedCalled {
		t.Errorf("expected applier to be called")
	}

	// Verificar que el evento local quedó procesado en la base de datos
	pending, _, _ := db.GetPendingOutbox(context.Background(), 4, 10)
	if len(pending) != 0 {
		t.Errorf("expected 0 pending outbox events after sync, got %d", len(pending))
	}

	// Verificar que el evento de la nube quedó guardado en sync_inbox
	inboxCount, _ := db.CountPendingInbox(context.Background(), 4)
	if inboxCount != 1 {
		t.Errorf("expected 1 event in sync_inbox, got %d", inboxCount)
	}
}
