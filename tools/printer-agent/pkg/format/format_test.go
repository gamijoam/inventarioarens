package format

import (
	"strings"
	"testing"
)

func TestSanitize(t *testing.T) {
	tests := []struct {
		name     string
		input    string
		expected string
	}{
		{
			name:     "accents and special characters",
			input:    "Café con leche y azúcar en España ¿cuánto?",
			expected: "Cafe con leche y azucar en Espana ?cuanto?\n",
		},
		{
			name:     "strip non-printable control characters",
			input:    "Hello\x00\x05World\t!",
			expected: "HelloWorld\t!\n",
		},
		{
			name:     "truncate long lines exceeding 64 chars",
			input:    "This is an extremely long line that should definitely be truncated because it exceeds sixty four characters by a lot",
			expected: "This is an extremely long line that should definitely be trun...\n",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := Sanitize(tt.input)
			if got != tt.expected {
				t.Errorf("Sanitize() = %q, want %q", got, tt.expected)
			}
		})
	}
}

func TestBuildPlainTicket(t *testing.T) {
	ticket := map[string]interface{}{
		"tenant": map[string]interface{}{
			"name": "Super Tienda Arenas",
			"slug": "arenas-principal",
		},
		"profile": map[string]interface{}{
			"paper_width_mm":        80,
			"logo_text":             "ARENAS TIENDA",
			"header_text":           "RIF: J-12345678-9",
			"show_tenant_slug":      true,
			"show_sale_number":      true,
			"show_paid_at":          true,
			"show_cashier":          true,
			"show_customer":         true,
			"show_total_local":      true,
			"show_non_fiscal_text":  true,
			"legal_text":            "DOCUMENTO NO FISCAL",
		},
		"pos_order": map[string]interface{}{
			"id":                1001,
			"sale_id":           2001,
			"paid_at":           "2026-09-27T14:30:00Z",
			"cashier_name":      "Juan Perez",
			"customer_name":     "Maria Gomez",
			"customer_document": "V-19876543",
		},
		"items": []interface{}{
			map[string]interface{}{
				"product_name": "Aceite Motor 20W50",
				"quantity":     2.0,
				"unit_price":   5.50,
				"total":        11.00,
			},
		},
		"totals": map[string]interface{}{
			"total_base_amount":  11.00,
			"total_local_amount": 440.00,
			"paid_base_amount":   11.00,
		},
		"payments": []interface{}{
			map[string]interface{}{
				"method":   "Efectivo",
				"currency": "USD",
				"amount":   11.00,
			},
		},
	}

	result := BuildPlainTicket(ticket)

	mustContain := []string{
		"ARENAS TIENDA",
		"Ticket POS #1001",
		"Venta #2001",
		"Cajero: Juan Perez",
		"Cliente: Maria Gomez",
		"Aceite Motor 20W50",
		"Total USD: $11.00",
		"Total VES: Bs 440,00",
		"Efectivo USD: $11.00",
		"DOCUMENTO NO FISCAL",
	}

	for _, str := range mustContain {
		if !strings.Contains(result, str) {
			t.Errorf("BuildPlainTicket() missing substring %q\nFull output:\n%s", str, result)
		}
	}
}

func TestBuildPlainReportZ(t *testing.T) {
	ticket := map[string]interface{}{
		"z_number":      42,
		"cash_register": "Caja 1",
		"branch":        "Sucursal Centro",
		"cashier":       "Juan Perez",
		"opened_at":     "2026-09-27 08:00:00",
		"closed_at":     "2026-09-27 18:00:00",
		"profile": map[string]interface{}{
			"paper_width_mm": 58,
			"logo_text":      "MI NEGOCIO",
		},
		"totals": map[string]interface{}{
			"orders_count":         15,
			"paid_base_amount":     150.50,
			"paid_local_amount":    6020.00,
			"difference_cash_usd":  0.0,
			"difference_cash_ves":  0.0,
		},
		"payments": []interface{}{
			map[string]interface{}{
				"method":      "Efectivo",
				"currency":    "USD",
				"amount_base": 150.50,
			},
		},
	}

	result := BuildPlainReportZ(ticket)

	mustContain := []string{
		"MI NEGOCIO",
		"REPORTE Z",
		"Z #42",
		"Caja: Caja 1",
		"Tickets: 15",
		"Total USD: $150.50",
		"Total VES: Bs 6.020,00",
		"Efectivo: $150.50",
	}

	for _, str := range mustContain {
		if !strings.Contains(result, str) {
			t.Errorf("BuildPlainReportZ() missing substring %q\nFull output:\n%s", str, result)
		}
	}
}
