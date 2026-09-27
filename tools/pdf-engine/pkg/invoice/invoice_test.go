package invoice

import (
	"bytes"
	"testing"
)

func TestGenerateInvoicePDF_CreatesValidPDF(t *testing.T) {
	data := InvoiceData{
		CompanyName:  "INVERSIONES ARENS C.A.",
		CompanyRif:   "J-12345678-9",
		Address:      "Av. Bolivar, Edif Arens, Caracas",
		Phone:        "0212-9876543",
		InvoiceNo:    "FAC-2026-0089",
		Date:         "27/09/2026",
		DueDate:      "04/10/2026",
		CustomerName: "Comercializadora Avila C.A.",
		CustomerRif:  "J-99887766-5",
		CustomerAddr: "Zona Industrial San Martin",
		Items: []InvoiceItem{
			{SKU: "HAR-01", Description: "Harina Pan 1kg x Bulto 20un", Quantity: 5, UnitPrice: 22.00, Total: 110.00},
			{SKU: "ARR-02", Description: "Arroz Mary Tradicional 24un", Quantity: 3, UnitPrice: 28.50, Total: 85.50},
		},
		SubtotalUSD:  195.50,
		TaxUSD:       31.28,
		TotalUSD:     226.78,
		ExchangeRate: 46.50,
		TotalVES:     10545.27,
	}

	gen := NewInvoiceGenerator()
	pdfBytes, err := gen.GeneratePDF(data)
	if err != nil {
		t.Fatalf("Unexpected error generating invoice: %v", err)
	}

	if len(pdfBytes) < 1000 {
		t.Fatalf("Invoice PDF too small (%d bytes)", len(pdfBytes))
	}

	if !bytes.HasPrefix(pdfBytes, []byte("%PDF-")) {
		t.Errorf("Missing %%PDF- magic header")
	}
}
