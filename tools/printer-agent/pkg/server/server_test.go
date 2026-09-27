package server

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"

	"inventarioarens/printer-agent/pkg/printer"
)

type mockPrinter struct {
	lastText    string
	lastPrinter string
	lastOptions printer.PrintOptions
	err         error
}

func (m *mockPrinter) Print(text string, printerName string, options printer.PrintOptions) (*printer.PrintResult, error) {
	m.lastText = text
	m.lastPrinter = printerName
	m.lastOptions = options
	if m.err != nil {
		return &printer.PrintResult{Ok: false, Message: m.err.Error()}, m.err
	}
	return &printer.PrintResult{
		Ok:      true,
		Status:  "printed",
		Message: "Impreso correctamente",
		Printer: printerName,
	}, nil
}

func TestHealthCheck(t *testing.T) {
	s := NewServer(17777, &mockPrinter{})
	req := httptest.NewRequest("GET", "/health", nil)
	w := httptest.NewRecorder()

	s.ServeHTTP(w, req)

	res := w.Result()
	defer res.Body.Close()

	if res.StatusCode != http.StatusOK {
		t.Fatalf("Expected 200 OK, got %d", res.StatusCode)
	}

	var data map[string]interface{}
	if err := json.NewDecoder(res.Body).Decode(&data); err != nil {
		t.Fatalf("Failed to decode response: %v", err)
	}

	if data["ok"] != true {
		t.Errorf("Expected ok=true, got %v", data["ok"])
	}
	if data["service"] != "inventarioarens-printer-agent" {
		t.Errorf("Unexpected service name: %v", data["service"])
	}
	if int(data["port"].(float64)) != 17777 {
		t.Errorf("Expected port 17777, got %v", data["port"])
	}
}

func TestOptionsCorsPreflight(t *testing.T) {
	s := NewServer(17777, &mockPrinter{})
	req := httptest.NewRequest("OPTIONS", "/print", nil)
	w := httptest.NewRecorder()

	s.ServeHTTP(w, req)

	res := w.Result()
	defer res.Body.Close()

	if res.StatusCode != http.StatusNoContent {
		t.Fatalf("Expected 204 No Content, got %d", res.StatusCode)
	}

	if w.Header().Get("Access-Control-Allow-Origin") != "*" {
		t.Errorf("Missing CORS origin header")
	}
}

func TestPrintDigital_WithBase64PDF(t *testing.T) {
	tmpDir := t.TempDir()
	s := NewServer(17777, &mockPrinter{})

	pdfContent := "%PDF-1.4 mock content"
	pdfBase64 := base64.StdEncoding.EncodeToString([]byte(pdfContent))

	payload := map[string]interface{}{
		"output": "digital",
		"station": map[string]interface{}{
			"digital_directory": tmpDir,
		},
		"payload": map[string]interface{}{
			"tenant": map[string]interface{}{
				"slug": "test-tenant",
			},
			"pos_order": map[string]interface{}{
				"id": 1234,
			},
		},
		"pdf_base64": pdfBase64,
	}

	body, _ := json.Marshal(payload)
	req := httptest.NewRequest("POST", "/print", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()

	s.ServeHTTP(w, req)

	res := w.Result()
	defer res.Body.Close()

	if res.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(res.Body)
		t.Fatalf("Expected 200 OK, got %d: %s", res.StatusCode, string(b))
	}

	var respData map[string]interface{}
	json.NewDecoder(res.Body).Decode(&respData)

	pdfPath, ok := respData["pdf_path"].(string)
	if !ok || pdfPath == "" {
		t.Fatalf("Missing pdf_path in response")
	}

	// Verify file was written
	written, err := os.ReadFile(pdfPath)
	if err != nil {
		t.Fatalf("Failed to read created PDF: %v", err)
	}
	if string(written) != pdfContent {
		t.Errorf("File content mismatch: got %q, want %q", string(written), pdfContent)
	}
}

func TestPrintThermal_Success(t *testing.T) {
	mock := &mockPrinter{}
	s := NewServer(17777, mock)

	payload := map[string]interface{}{
		"output": "thermal",
		"station": map[string]interface{}{
			"printer_type": "network",
			"network_host": "192.168.1.100",
			"network_port": 9100,
		},
		"payload": map[string]interface{}{
			"tenant": map[string]interface{}{
				"name": "Tienda Test",
			},
			"pos_order": map[string]interface{}{
				"id": 999,
			},
		},
	}

	body, _ := json.Marshal(payload)
	req := httptest.NewRequest("POST", "/print", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()

	s.ServeHTTP(w, req)

	res := w.Result()
	defer res.Body.Close()

	if res.StatusCode != http.StatusOK {
		t.Fatalf("Expected 200 OK, got %d", res.StatusCode)
	}

	var respData map[string]interface{}
	json.NewDecoder(res.Body).Decode(&respData)

	if respData["ok"] != true {
		t.Errorf("Expected ok=true, got %v", respData["ok"])
	}
	if respData["status"] != "printed" {
		t.Errorf("Expected status='printed', got %v", respData["status"])
	}
	if !strings.Contains(mock.lastText, "TIENDA TEST") {
		t.Errorf("Printer was not called with formatted ticket text")
	}
}

func TestPrint_InvalidJSON(t *testing.T) {
	s := NewServer(17777, &mockPrinter{})
	req := httptest.NewRequest("POST", "/print", strings.NewReader("invalid-json{"))
	req.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()

	s.ServeHTTP(w, req)

	res := w.Result()
	defer res.Body.Close()

	if res.StatusCode != http.StatusBadRequest {
		t.Fatalf("Expected 400 Bad Request, got %d", res.StatusCode)
	}
}

func TestRouteNotFound(t *testing.T) {
	s := NewServer(17777, &mockPrinter{})
	req := httptest.NewRequest("GET", "/unknown", nil)
	w := httptest.NewRecorder()

	s.ServeHTTP(w, req)

	res := w.Result()
	defer res.Body.Close()

	if res.StatusCode != http.StatusNotFound {
		t.Fatalf("Expected 404 Not Found, got %d", res.StatusCode)
	}
}
