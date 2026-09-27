package index

import (
	"testing"
)

func TestCatalogIndex(t *testing.T) {
	idx := NewCatalogIndex()

	products := []Product{
		{
			ID:          1,
			TenantID:    1,
			SKU:         "ACE-001",
			Barcode:     "7591234567890",
			Name:        "Aceite para Motor 20W-50 Mineral",
			Description: "Lubricante de alto rendimiento para motores a gasolina",
			Price:       5.50,
			Stock:       120,
		},
		{
			ID:          2,
			TenantID:    1,
			SKU:         "FIL-002",
			Barcode:     "7599876543210",
			Name:        "Filtro de Aceite PH8A",
			Description: "Filtro blindado para vehiculos livianos",
			Price:       4.00,
			Stock:       45,
		},
		{
			ID:          3,
			TenantID:    2, // Different tenant!
			SKU:         "ACE-001",
			Barcode:     "7591234567890",
			Name:        "Aceite Otro Tenant",
			Price:       6.00,
			Stock:       10,
		},
	}

	for _, p := range products {
		idx.AddOrUpdate(p)
	}

	if idx.Count() != 3 {
		t.Fatalf("Expected 3 items in index, got %d", idx.Count())
	}

	// 1. Exact Barcode Lookup
	t.Run("Exact Barcode Lookup", func(t *testing.T) {
		p := idx.GetByBarcode("7591234567890", 1)
		if p == nil {
			t.Fatal("Expected product by barcode, got nil")
		}
		if p.ID != 1 {
			t.Errorf("Expected ID 1, got %d", p.ID)
		}

		// Cross-tenant check: tenant 2 has its own product
		pTenant2 := idx.GetByBarcode("7591234567890", 2)
		if pTenant2 == nil || pTenant2.ID != 3 {
			t.Errorf("Expected product ID 3 for tenant 2, got %v", pTenant2)
		}
	})

	// 2. Exact SKU Prefix Search
	t.Run("SKU Search", func(t *testing.T) {
		results := idx.Search("FIL-002", 1, 10)
		if len(results) == 0 {
			t.Fatal("Expected search result for SKU")
		}
		if results[0].Product.ID != 2 {
			t.Errorf("Expected ID 2, got %d", results[0].Product.ID)
		}
	})

	// 3. Name Full-text and Prefix Search
	t.Run("Name Prefix Search", func(t *testing.T) {
		results := idx.Search("aceite", 1, 10)
		// Should match both "Aceite para Motor..." and "Filtro de Aceite..."
		if len(results) != 2 {
			t.Fatalf("Expected 2 matches for 'aceite', got %d", len(results))
		}
	})

	// 4. Case and Accent Insensitive
	t.Run("Case and Accent Insensitive", func(t *testing.T) {
		results := idx.Search("ACEÍTE", 1, 10)
		if len(results) == 0 {
			t.Fatal("Expected matches for 'ACEÍTE' with accent and uppercase")
		}
	})

	// 5. Tenant Isolation
	t.Run("Tenant Isolation", func(t *testing.T) {
		results := idx.Search("Aceite", 1, 10)
		for _, r := range results {
			if r.Product.TenantID != 1 {
				t.Errorf("Leaked product from tenant %d in search for tenant 1", r.Product.TenantID)
			}
		}
	})
}
