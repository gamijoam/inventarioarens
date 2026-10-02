package proxy

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestNewAPIProxy_InjectsConfiguredTenant(t *testing.T) {
	var receivedTenantHeader string

	// Fake backend server (simulates Laravel)
	backendServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		receivedTenantHeader = r.Header.Get("X-Tenant")
		w.WriteHeader(http.StatusOK)
		w.Write([]byte(`{"status":"ok"}`))
	}))
	defer backendServer.Close()

	// Create our dynamic proxy configured with tenant "repuestosavilacar"
	tenantSlug := "repuestosavilacar"
	proxyHandler, err := NewAPIProxy(backendServer.URL, tenantSlug)
	if err != nil {
		t.Fatalf("unexpected error creating proxy: %v", err)
	}

	// Make request through proxy WITHOUT X-Tenant header
	req := httptest.NewRequest("GET", "/api/products", nil)
	rr := httptest.NewRecorder()

	proxyHandler.ServeHTTP(rr, req)

	if rr.Code != http.StatusOK {
		t.Errorf("expected 200 OK, got %d", rr.Code)
	}

	// Verify the backend received the injected tenant slug!
	if receivedTenantHeader != "repuestosavilacar" {
		t.Errorf("expected backend to receive X-Tenant 'repuestosavilacar', got %q", receivedTenantHeader)
	}
}

func TestNewAPIProxy_PreservesExistingTenantIfPresent(t *testing.T) {
	var receivedTenantHeader string

	backendServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		receivedTenantHeader = r.Header.Get("X-Tenant")
		w.WriteHeader(http.StatusOK)
	}))
	defer backendServer.Close()

	tenantSlug := "default-tenant"
	proxyHandler, err := NewAPIProxy(backendServer.URL, tenantSlug)
	if err != nil {
		t.Fatalf("unexpected error creating proxy: %v", err)
	}

	// Make request WITH custom X-Tenant header
	req := httptest.NewRequest("GET", "/api/products", nil)
	req.Header.Set("X-Tenant", "specific-tenant")
	rr := httptest.NewRecorder()

	proxyHandler.ServeHTTP(rr, req)

	if receivedTenantHeader != "specific-tenant" {
		t.Errorf("expected backend to keep explicit X-Tenant 'specific-tenant', got %q", receivedTenantHeader)
	}
}
