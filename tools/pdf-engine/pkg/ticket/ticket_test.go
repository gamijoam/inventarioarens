package ticket

import (
	"bytes"
	"testing"
)

func TestGenerateThermalTicket_CreatesValidPDF(t *testing.T) {
	data := ReceiptData{
		CompanyName:  "INVERSIONES ARENS C.A.",
		CompanyRif:   "J-12345678-9",
		BranchName:   "Sucursal Principal",
		Phone:        "0414-1234567",
		DocumentNo:   "FACT-000123",
		Date:         "2026-09-27 15:30",
		CashierName:  "Gabriel",
		CustomerName: "Juan Perez",
		CustomerDoc:  "V-18765432",
		Items: []ReceiptItem{
			{Description: "Harina Pan 1kg", Quantity: 2, UnitPrice: 1.20, Total: 2.40},
			{Description: "Arroz Mary 1kg", Quantity: 3, UnitPrice: 1.50, Total: 4.50},
			{Description: "Aceite Mazeite 1L", Quantity: 1, UnitPrice: 3.50, Total: 3.50},
		},
		TotalUSD:     10.40,
		ExchangeRate: 46.50,
		TotalVES:     483.60,
		Payments: []ReceiptPayment{
			{Method: "Efectivo USD", AmountUSD: 5.00, AmountVES: 0.0},
			{Method: "Pago Movil", AmountUSD: 5.40, AmountVES: 251.10},
		},
		FooterNotes: "Conserve este ticket para cualquier cambio.",
		WidthMM:     80,
	}

	gen := NewTicketGenerator()
	pdfBytes, err := gen.GeneratePDF(data)
	if err != nil {
		t.Fatalf("Unexpected error generating ticket: %v", err)
	}

	if len(pdfBytes) < 500 {
		t.Fatalf("PDF too small (%d bytes), expected valid PDF", len(pdfBytes))
	}

	// Must begin with PDF magic header %PDF-
	if !bytes.HasPrefix(pdfBytes, []byte("%PDF-")) {
		t.Errorf("Resulting file does not have %%PDF- header")
	}
}

func TestGenerateThermalTicket_Supports58MM(t *testing.T) {
	data := ReceiptData{
		CompanyName: "Tiendas Arens 58mm",
		DocumentNo:  "TKT-58-001",
		WidthMM:     58,
		TotalUSD:    5.00,
	}

	gen := NewTicketGenerator()
	pdfBytes, err := gen.GeneratePDF(data)
	if err != nil {
		t.Fatalf("Unexpected error: %v", err)
	}

	if !bytes.HasPrefix(pdfBytes, []byte("%PDF-")) {
		t.Errorf("Expected valid PDF header")
	}
}
