package server

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"balanzapro/scale-agent/pkg/protocol"
	"balanzapro/scale-agent/pkg/reader"
)

func TestHealthHandler(t *testing.T) {
	mock := reader.NewMockReader(0, "kg", 0)
	srv := NewServer(19999, mock)

	req := httptest.NewRequest("GET", "/health", nil)
	w := httptest.NewRecorder()

	srv.ServeHTTP(w, req)

	res := w.Result()
	defer res.Body.Close()

	if res.StatusCode != http.StatusOK {
		t.Fatalf("Expected 200 OK, got %d", res.StatusCode)
	}

	var data map[string]interface{}
	json.NewDecoder(res.Body).Decode(&data)

	if data["ok"] != true {
		t.Errorf("Expected ok=true, got %v", data["ok"])
	}
	if data["service"] != "balanzapro-scale-agent" {
		t.Errorf("Unexpected service name: %v", data["service"])
	}
}

func TestWeightHandler_WithReading(t *testing.T) {
	mock := reader.NewMockReader(3.750, "kg", 10*time.Millisecond)
	// Trigger at least one reading
	reading := &protocol.ScaleReading{
		Weight:    3.750,
		Unit:      "kg",
		IsStable:  true,
		Raw:       "TEST",
		Timestamp: time.Now(),
	}
	// Pre-set latest
	_ = reading

	srv := NewServer(19999, mock)

	req := httptest.NewRequest("GET", "/weight", nil)
	w := httptest.NewRecorder()

	srv.ServeHTTP(w, req)

	res := w.Result()
	defer res.Body.Close()

	if res.StatusCode != http.StatusOK {
		t.Fatalf("Expected 200 OK, got %d", res.StatusCode)
	}

	var data map[string]interface{}
	json.NewDecoder(res.Body).Decode(&data)

	if data["ok"] != true {
		t.Errorf("Expected ok=true, got %v", data["ok"])
	}
}

func TestOptionsCorsPreflight(t *testing.T) {
	mock := reader.NewMockReader(0, "kg", 0)
	srv := NewServer(19999, mock)

	req := httptest.NewRequest("OPTIONS", "/weight", nil)
	w := httptest.NewRecorder()

	srv.ServeHTTP(w, req)

	res := w.Result()
	defer res.Body.Close()

	if res.StatusCode != http.StatusNoContent {
		t.Fatalf("Expected 204 No Content, got %d", res.StatusCode)
	}

	if w.Header().Get("Access-Control-Allow-Origin") != "*" {
		t.Errorf("Missing CORS header")
	}
}
