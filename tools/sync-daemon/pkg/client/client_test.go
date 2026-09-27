package client_test

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"inventarioarens/sync-daemon/pkg/client"
)

func TestClient_Push(t *testing.T) {
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			t.Errorf("expected POST, got %s", r.Method)
		}
		if r.URL.Path != "/sync/events/push" {
			t.Errorf("expected path /sync/events/push, got %s", r.URL.Path)
		}
		if r.Header.Get("Authorization") != "Bearer test-token-123" {
			t.Errorf("expected Bearer test-token-123, got %s", r.Header.Get("Authorization"))
		}
		if r.Header.Get("X-Tenant") != "asiamoto" {
			t.Errorf("expected X-Tenant asiamoto, got %s", r.Header.Get("X-Tenant"))
		}

		var body map[string]interface{}
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
			t.Fatalf("failed to decode request body: %v", err)
		}

		if body["origin_node_code"] != "LOCAL-01" {
			t.Errorf("expected origin_node_code LOCAL-01, got %v", body["origin_node_code"])
		}

		events, ok := body["events"].([]interface{})
		if !ok || len(events) != 2 {
			t.Errorf("expected 2 events in payload, got %d", len(events))
		}

		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"data": {"received": 2, "duplicated": 0}}`))
	}))
	defer ts.Close()

	c := client.New(client.Options{Timeout: 5 * time.Second})

	events := []client.OutboxEvent{
		{
			EventUUID:     "uuid-1",
			EventType:     "sale.created",
			AggregateType: "sale",
			AggregateID:   "101",
			Payload:       map[string]interface{}{"total": 50.0},
			OccurredAt:    "2026-09-27T10:00:00Z",
		},
		{
			EventUUID:     "uuid-2",
			EventType:     "cash_movement.created",
			AggregateType: "cash",
			AggregateID:   "202",
			Payload:       map[string]interface{}{"amount": 20.0},
			OccurredAt:    "2026-09-27T10:01:00Z",
		},
	}

	resp, err := c.Push(context.Background(), ts.URL, "test-token-123", "asiamoto", "LOCAL-01", events)
	if err != nil {
		t.Fatalf("expected no error, got: %v", err)
	}

	if resp.Received != 2 {
		t.Errorf("expected 2 received, got %d", resp.Received)
	}
	if resp.Duplicated != 0 {
		t.Errorf("expected 0 duplicated, got %d", resp.Duplicated)
	}
}

func TestClient_Pull(t *testing.T) {
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			t.Errorf("expected GET, got %s", r.Method)
		}
		if r.URL.Path != "/sync/events/pull" {
			t.Errorf("expected path /sync/events/pull, got %s", r.URL.Path)
		}
		if r.URL.Query().Get("node_code") != "LOCAL-01" {
			t.Errorf("expected node_code LOCAL-01, got %s", r.URL.Query().Get("node_code"))
		}
		if r.URL.Query().Get("limit") != "50" {
			t.Errorf("expected limit 50, got %s", r.URL.Query().Get("limit"))
		}

		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{
			"data": [
				{
					"id": 1,
					"event_uuid": "cloud-uuid-1",
					"event_type": "product.created",
					"aggregate_type": "product",
					"aggregate_id": "500",
					"payload": {"name": "Aceite 20W50", "base_price": 5.50},
					"occurred_at": "2026-09-27T09:00:00Z"
				}
			]
		}`))
	}))
	defer ts.Close()

	c := client.New(client.Options{Timeout: 5 * time.Second})

	events, err := c.Pull(context.Background(), ts.URL, "token-abc", "asiamoto", "LOCAL-01", 50)
	if err != nil {
		t.Fatalf("expected no error, got: %v", err)
	}

	if len(events) != 1 {
		t.Fatalf("expected 1 event, got %d", len(events))
	}
	if events[0].EventUUID != "cloud-uuid-1" {
		t.Errorf("expected uuid 'cloud-uuid-1', got '%s'", events[0].EventUUID)
	}
	if events[0].EventType != "product.created" {
		t.Errorf("expected event_type 'product.created', got '%s'", events[0].EventType)
	}
}

func TestClient_Ack(t *testing.T) {
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			t.Errorf("expected POST, got %s", r.Method)
		}
		if r.URL.Path != "/sync/events/cloud-uuid-1/ack" {
			t.Errorf("expected path /sync/events/cloud-uuid-1/ack, got %s", r.URL.Path)
		}
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"ok": true}`))
	}))
	defer ts.Close()

	c := client.New(client.Options{Timeout: 5 * time.Second})
	err := c.Ack(context.Background(), ts.URL, "token-abc", "asiamoto", "cloud-uuid-1")
	if err != nil {
		t.Fatalf("expected no error on ack, got: %v", err)
	}
}

func TestClient_ServerError(t *testing.T) {
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
		_, _ = w.Write([]byte(`{"message": "Unauthenticated"}`))
	}))
	defer ts.Close()

	c := client.New(client.Options{Timeout: 5 * time.Second})
	_, err := c.Pull(context.Background(), ts.URL, "bad-token", "asiamoto", "LOCAL-01", 50)
	if err == nil {
		t.Fatalf("expected error for 401 Unauthorized, got nil")
	}
}
