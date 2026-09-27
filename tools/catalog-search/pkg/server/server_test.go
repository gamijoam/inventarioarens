package server

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"balanzapro/catalog-search/pkg/index"
)

func TestCatalogSearchServer(t *testing.T) {
	idx := index.NewCatalogIndex()
	srv := NewServer(18888, idx)

	// 1. Test POST /index
	products := []index.Product{
		{
			ID:       101,
			TenantID: 1,
			SKU:      "HAR-001",
			Barcode:  "759000111222",
			Name:     "Harina Pan 1kg",
			Price:    1.20,
			Stock:    500,
		},
	}
	body, _ := json.Marshal(products)
	req := httptest.NewRequest("POST", "/index", bytes.NewReader(body))
	w := httptest.NewRecorder()
	srv.ServeHTTP(w, req)

	if w.Result().StatusCode != http.StatusOK {
		t.Fatalf("Expected 200 OK for /index, got %d", w.Result().StatusCode)
	}

	// 2. Test GET /search
	reqSearch := httptest.NewRequest("GET", "/search?q=harina&tenant_id=1", nil)
	wSearch := httptest.NewRecorder()
	srv.ServeHTTP(wSearch, reqSearch)

	if wSearch.Result().StatusCode != http.StatusOK {
		t.Fatalf("Expected 200 OK for /search, got %d", wSearch.Result().StatusCode)
	}

	var searchResp map[string]interface{}
	json.NewDecoder(wSearch.Result().Body).Decode(&searchResp)

	results, ok := searchResp["results"].([]interface{})
	if !ok || len(results) != 1 {
		t.Fatalf("Expected 1 search result, got %v", results)
	}

	// 3. Test GET /barcode
	reqBarcode := httptest.NewRequest("GET", "/barcode?code=759000111222&tenant_id=1", nil)
	wBarcode := httptest.NewRecorder()
	srv.ServeHTTP(wBarcode, reqBarcode)

	if wBarcode.Result().StatusCode != http.StatusOK {
		t.Fatalf("Expected 200 OK for /barcode, got %d", wBarcode.Result().StatusCode)
	}
}
