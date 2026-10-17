package server

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"balanzapro/scale-agent/pkg/protocol"
	"balanzapro/scale-agent/pkg/reader"
)

func TestHealthHandler(t *testing.T) {
	srv := NewServer(19999, reader.NewDisconnectedReader())
	req := httptest.NewRequest(http.MethodGet, "/health", nil)
	w := httptest.NewRecorder()
	srv.ServeHTTP(w, req)
	if w.Code != http.StatusOK {
		t.Fatalf("status = %d", w.Code)
	}
	var data map[string]any
	if err := json.Unmarshal(w.Body.Bytes(), &data); err != nil {
		t.Fatal(err)
	}
	if data["status"] != "disconnected" {
		t.Fatalf("expected disconnected, got %v", data["status"])
	}
}

func TestWeightWithoutHardwareDoesNotInventZero(t *testing.T) {
	srv := NewServer(19999, reader.NewDisconnectedReader())
	req := httptest.NewRequest(http.MethodGet, "/weight", nil)
	w := httptest.NewRecorder()
	srv.ServeHTTP(w, req)
	var data map[string]any
	if err := json.Unmarshal(w.Body.Bytes(), &data); err != nil {
		t.Fatal(err)
	}
	if _, exists := data["weight"]; exists {
		t.Fatalf("weight must be omitted while disconnected: %v", data)
	}
	if data["status"] != "waiting_for_reading" {
		t.Fatalf("status = %v", data["status"])
	}
}

type fixedReader struct{ reading *protocol.ScaleReading }

func (r fixedReader) Start(_ context.Context)           {}
func (r fixedReader) GetLatest() *protocol.ScaleReading { return r.reading }
func (r fixedReader) Subscribe() <-chan *protocol.ScaleReading {
	ch := make(chan *protocol.ScaleReading)
	close(ch)
	return ch
}
func (r fixedReader) Unsubscribe(<-chan *protocol.ScaleReading) {}

func TestWeightHandlerReturnsCurrentReading(t *testing.T) {
	reading := &protocol.ScaleReading{Weight: 3.75, Unit: "kg", IsStable: true, Raw: "3.750 kg", Timestamp: time.Now()}
	srv := NewServer(19999, fixedReader{reading: reading})
	req := httptest.NewRequest(http.MethodGet, "/weight", nil)
	w := httptest.NewRecorder()
	srv.ServeHTTP(w, req)
	var data map[string]any
	if err := json.Unmarshal(w.Body.Bytes(), &data); err != nil {
		t.Fatal(err)
	}
	if data["weight"] != 3.75 || data["status"] != "ready" {
		t.Fatalf("unexpected response: %v", data)
	}
}

func TestTrustedPrivateNetworkOriginPreflight(t *testing.T) {
	srv := NewServer(19999, reader.NewDisconnectedReader())
	req := httptest.NewRequest(http.MethodOptions, "/weight", nil)
	req.Header.Set("Origin", "http://192.168.1.25:8787")
	req.Header.Set("Access-Control-Request-Private-Network", "true")
	w := httptest.NewRecorder()
	srv.ServeHTTP(w, req)
	if w.Code != http.StatusNoContent {
		t.Fatalf("status = %d", w.Code)
	}
	if got := w.Header().Get("Access-Control-Allow-Origin"); got != "http://192.168.1.25:8787" {
		t.Fatalf("allow-origin = %q", got)
	}
	if got := w.Header().Get("Access-Control-Allow-Private-Network"); got != "true" {
		t.Fatalf("allow-private-network = %q", got)
	}
}

func TestUntrustedOriginRejected(t *testing.T) {
	srv := NewServer(19999, reader.NewDisconnectedReader())
	req := httptest.NewRequest(http.MethodPost, "/weight", nil)
	req.Header.Set("Origin", "https://attacker.example")
	w := httptest.NewRecorder()
	srv.ServeHTTP(w, req)
	if w.Code != http.StatusForbidden {
		t.Fatalf("status = %d", w.Code)
	}
}
