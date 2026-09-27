package server

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/gorilla/websocket"
	"inventarioarens/ws-hub/pkg/hub"
)

func TestHealthCheck(t *testing.T) {
	h := hub.NewHub()
	srv := NewServer(8089, h, "")

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
	if data["service"] != "ws-hub" {
		t.Errorf("Unexpected service: %v", data["service"])
	}
}

func TestPublish_DeliversToSubscribedWebSocket(t *testing.T) {
	h := hub.NewHub()
	srv := NewServer(8089, h, "")

	ts := httptest.NewServer(srv)
	defer ts.Close()

	wsURL := "ws" + strings.TrimPrefix(ts.URL, "http") + "/ws?channels=tenant:4,global"

	ws, _, err := websocket.DefaultDialer.Dial(wsURL, nil)
	if err != nil {
		t.Fatalf("Failed to connect websocket: %v", err)
	}
	defer ws.Close()

	// Wait for registration
	time.Sleep(20 * time.Millisecond)

	if h.ClientCount() != 1 {
		t.Fatalf("Expected 1 client registered in hub, got %d", h.ClientCount())
	}

	// Publish an event via HTTP POST /publish
	publishPayload := map[string]interface{}{
		"channel": "tenant:4",
		"event":   "rate.updated",
		"data": map[string]interface{}{
			"currency": "USD",
			"rate":     45.50,
		},
	}
	body, _ := json.Marshal(publishPayload)

	req, _ := http.NewRequest("POST", ts.URL+"/publish", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatalf("Failed to publish: %v", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		t.Fatalf("Expected 200 OK from publish, got %d", resp.StatusCode)
	}

	// Verify WebSocket received the message
	_ = ws.SetReadDeadline(time.Now().Add(1 * time.Second))
	_, msgBytes, err := ws.ReadMessage()
	if err != nil {
		t.Fatalf("Failed to read websocket message: %v", err)
	}

	var received map[string]interface{}
	if err := json.Unmarshal(msgBytes, &received); err != nil {
		t.Fatalf("Failed to unmarshal received msg: %v", err)
	}

	if received["event"] != "rate.updated" {
		t.Errorf("Expected event 'rate.updated', got %v", received["event"])
	}
}

func TestPublish_UnauthorizedWhenKeyRequired(t *testing.T) {
	h := hub.NewHub()
	srv := NewServer(8089, h, "secret-token")

	payload := map[string]interface{}{
		"channel": "global",
		"event":   "alert",
	}
	body, _ := json.Marshal(payload)

	// 1. Without auth header -> 401
	req := httptest.NewRequest("POST", "/publish", bytes.NewReader(body))
	w := httptest.NewRecorder()
	srv.ServeHTTP(w, req)

	if w.Result().StatusCode != http.StatusUnauthorized {
		t.Errorf("Expected 401 Unauthorized, got %d", w.Result().StatusCode)
	}

	// 2. With correct auth header -> 200
	reqAuth := httptest.NewRequest("POST", "/publish", bytes.NewReader(body))
	reqAuth.Header.Set("X-Auth-Key", "secret-token")
	wAuth := httptest.NewRecorder()
	srv.ServeHTTP(wAuth, reqAuth)

	if wAuth.Result().StatusCode != http.StatusOK {
		t.Errorf("Expected 200 OK with valid key, got %d", wAuth.Result().StatusCode)
	}
}

func TestWebSocket_DynamicSubscribe(t *testing.T) {
	h := hub.NewHub()
	srv := NewServer(8089, h, "")

	ts := httptest.NewServer(srv)
	defer ts.Close()

	wsURL := "ws" + strings.TrimPrefix(ts.URL, "http") + "/ws"

	ws, _, err := websocket.DefaultDialer.Dial(wsURL, nil)
	if err != nil {
		t.Fatalf("Failed to connect websocket: %v", err)
	}
	defer ws.Close()

	time.Sleep(20 * time.Millisecond)

	// Send subscribe action
	subMsg := map[string]string{
		"action":  "subscribe",
		"channel": "tenant:99",
	}
	if err := ws.WriteJSON(subMsg); err != nil {
		t.Fatalf("Failed to send subscribe message: %v", err)
	}

	time.Sleep(30 * time.Millisecond)

	// Publish to tenant:99
	h.Broadcast("tenant:99", []byte(`{"event":"order.created","id":1001}`))

	_ = ws.SetReadDeadline(time.Now().Add(1 * time.Second))
	_, msgBytes, err := ws.ReadMessage()
	if err != nil {
		t.Fatalf("Failed to read websocket message: %v", err)
	}

	var rec map[string]interface{}
	if err := json.Unmarshal(msgBytes, &rec); err != nil {
		t.Fatalf("Failed to unmarshal received msg: %v", err)
	}

	if rec["event"] != "order.created" {
		t.Errorf("Expected order.created, got %v", rec["event"])
	}
}
