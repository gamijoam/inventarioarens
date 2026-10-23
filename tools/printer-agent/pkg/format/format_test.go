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
			expected: "HelloWorld !\n",
		},
		{
			name:     "normalize only one trailing line break",
			input:    "Ticket\n",
			expected: "Ticket\n",
		},
		{
			name:     "replace unsupported unicode without shifting columns",
			input:    "Cafe\u0301 \u2022 \U0001F680",
			expected: "Cafe? ? ?\n",
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

func TestBuildPlainTicketKeepsAmountColumnWithinConfiguredWidth(t *testing.T) {
	ticket := map[string]interface{}{
		"profile": map[string]interface{}{
			"paper_width_mm":       80,
			"characters_per_line": 48,
			"show_non_fiscal_text": false,
		},
		"payments": []interface{}{
			map[string]interface{}{
				"method":   "Metodo de pago con una descripcion muy larga que excede la columna",
				"currency": "USD",
				"amount":   1.0,
			},
		},
	}

	lines := strings.Split(BuildPlainTicket(ticket), "\n")
	for _, line := range lines {
		if len(line) > 48 {
			t.Errorf("line exceeds configured width (%d): %q", 48, line)
		}
	}

	var paymentLine string
	for _, line := range lines {
		if strings.Contains(line, "$1.00") {
			paymentLine = line
			break
		}
	}
	if paymentLine == "" {
		t.Fatal("formatted payment amount was not found")
	}
	if len(paymentLine) != 48 || !strings.HasSuffix(paymentLine, "$1.00") {
		t.Errorf("payment amount is not aligned to the right edge: %q", paymentLine)
	}
}

func TestBuildPlainTicketIncludesCompanyDetailsWithoutRIFAndHonorsVisibility(t *testing.T) {
	ticket := map[string]interface{}{
		"tenant": map[string]interface{}{
			"name":         "Nombre fiscal oculto",
			"show_company": true,
			"company": map[string]interface{}{
				"razon_social":     "Empresa Fiscal C.A.",
				"domicilio_fiscal": "Avenida Principal Centro Comercial Local 14",
				"ciudad":           "Caracas",
				"estado":           "Miranda",
				"telefono":         "+58 212 555 0101",
				"correo":           "ventas@example.com",
				"website":          "https://example.com",
				"regimen":          "Contribuyente formal",
			},
		},
		"profile": map[string]interface{}{"paper_width_mm": 58, "show_non_fiscal_text": false},
	}
	result := BuildPlainTicket(ticket)
	flattened := strings.Join(strings.Fields(result), " ")
	for _, detail := range []string{
		"Empresa Fiscal C.A.",
		"Domicilio: Avenida Principal Centro Comercial Local 14",
		"Caracas, Miranda",
		"Telf: +58 212 555 0101",
		"Correo: ventas@example.com",
		"Web: https://example.com",
		"Regimen: Contribuyente formal",
	} {
		if !strings.Contains(flattened, detail) {
			t.Errorf("ticket missing company detail %q\nFull output:\n%s", detail, result)
		}
	}
	for _, line := range strings.Split(result, "\n") {
		if len(line) > 32 {
			t.Errorf("company line exceeds 58mm width: %q", line)
		}
	}

	ticket["tenant"].(map[string]interface{})["show_company"] = false
	hidden := BuildPlainTicket(ticket)
	if strings.Contains(hidden, "Empresa Fiscal") || strings.Contains(hidden, "Nombre fiscal oculto") || strings.Contains(hidden, "ventas@example.com") {
		t.Fatalf("company data should be hidden when show_company is false:\n%s", hidden)
	}
}

func TestBuildPlainReportZHonorsConfiguredCharacterWidth(t *testing.T) {
	result := BuildPlainReportZ(map[string]interface{}{
		"profile": map[string]interface{}{
			"paper_width_mm":       80,
			"characters_per_line": 24,
			"show_non_fiscal_text": false,
		},
		"totals": map[string]interface{}{
			"paid_base_amount": 123456789.12,
			"paid_local_amount": 123456789.12,
		},
	})
	for _, line := range strings.Split(result, "\n") {
		if len(line) > 24 {
			t.Errorf("report line exceeds configured width (%d): %q", 24, line)
		}
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
		"TOTAL USD:",
		"$11.00",
		"TOTAL VES:",
		"Bs 440,00",
		"Efectivo USD:",
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
		"Tickets:",
		"15",
		"Total USD:",
		"$150.50",
		"Total VES:",
		"Bs 6.020,00",
		"Efectivo:",
	}

	for _, str := range mustContain {
		if !strings.Contains(result, str) {
			t.Errorf("BuildPlainReportZ() missing substring %q\nFull output:\n%s", str, result)
		}
	}
}
