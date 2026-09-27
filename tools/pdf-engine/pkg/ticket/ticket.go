package ticket

import (
	"bytes"
	"fmt"
	"strings"

	"github.com/jung-kurt/gofpdf"
)

type ReceiptItem struct {
	Description string  `json:"description"`
	Quantity    float64 `json:"quantity"`
	UnitPrice   float64 `json:"unit_price"`
	Total       float64 `json:"total"`
}

type ReceiptPayment struct {
	Method    string  `json:"method"`
	AmountUSD float64 `json:"amount_usd"`
	AmountVES float64 `json:"amount_ves"`
}

type ReceiptData struct {
	CompanyName  string           `json:"company_name"`
	CompanyRif   string           `json:"company_rif"`
	BranchName   string           `json:"branch_name"`
	Phone        string           `json:"phone"`
	DocumentNo   string           `json:"document_no"`
	Date         string           `json:"date"`
	CashierName  string           `json:"cashier_name"`
	CustomerName string           `json:"customer_name"`
	CustomerDoc  string           `json:"customer_doc"`
	Items        []ReceiptItem    `json:"items"`
	SubtotalUSD  float64          `json:"subtotal_usd"`
	TaxUSD       float64          `json:"tax_usd"`
	TotalUSD     float64          `json:"total_usd"`
	ExchangeRate float64          `json:"exchange_rate"`
	TotalVES     float64          `json:"total_ves"`
	Payments     []ReceiptPayment `json:"payments"`
	FooterNotes  string           `json:"footer_notes"`
	WidthMM      float64          `json:"width_mm"` // 58 or 80
}

type TicketGenerator struct{}

func NewTicketGenerator() *TicketGenerator {
	return &TicketGenerator{}
}

func (g *TicketGenerator) GeneratePDF(data ReceiptData) ([]byte, error) {
	width := data.WidthMM
	if width <= 0 {
		width = 80
	}

	// Calculate approximate dynamic page height based on items and payments
	itemCount := len(data.Items)
	paymentCount := len(data.Payments)
	calcHeight := 100.0 + float64(itemCount*7) + float64(paymentCount*6)
	if calcHeight < 120.0 {
		calcHeight = 120.0
	}

	pdf := gofpdf.NewCustom(&gofpdf.InitType{
		UnitStr: "mm",
		Size:    gofpdf.SizeType{Wd: width, Ht: calcHeight},
	})

	pdf.SetMargins(3, 4, 3)
	pdf.SetAutoPageBreak(false, 0)
	pdf.AddPage()

	printableWd := width - 6

	// Company Header
	pdf.SetFont("Helvetica", "B", 10)
	pdf.CellFormat(printableWd, 5, sanitize(data.CompanyName), "", 1, "C", false, 0, "")

	pdf.SetFont("Helvetica", "", 8)
	if data.CompanyRif != "" {
		pdf.CellFormat(printableWd, 4, "RIF: "+sanitize(data.CompanyRif), "", 1, "C", false, 0, "")
	}
	if data.BranchName != "" {
		pdf.CellFormat(printableWd, 4, sanitize(data.BranchName), "", 1, "C", false, 0, "")
	}
	if data.Phone != "" {
		pdf.CellFormat(printableWd, 4, "Tlf: "+sanitize(data.Phone), "", 1, "C", false, 0, "")
	}

	// Separator
	pdf.CellFormat(printableWd, 3, strings.Repeat("-", int(width/2)), "", 1, "C", false, 0, "")

	// Document details
	pdf.SetFont("Helvetica", "B", 8)
	if data.DocumentNo != "" {
		pdf.CellFormat(printableWd*0.5, 4, "Ticket: "+data.DocumentNo, "", 0, "L", false, 0, "")
	}
	pdf.SetFont("Helvetica", "", 8)
	if data.Date != "" {
		pdf.CellFormat(printableWd*0.5, 4, data.Date, "", 1, "R", false, 0, "")
	} else {
		pdf.Ln(4)
	}

	if data.CashierName != "" {
		pdf.CellFormat(printableWd, 4, "Cajero: "+sanitize(data.CashierName), "", 1, "L", false, 0, "")
	}
	if data.CustomerName != "" {
		cust := sanitize(data.CustomerName)
		if data.CustomerDoc != "" {
			cust += " (" + data.CustomerDoc + ")"
		}
		pdf.CellFormat(printableWd, 4, "Cliente: "+cust, "", 1, "L", false, 0, "")
	}

	// Separator
	pdf.CellFormat(printableWd, 3, strings.Repeat("-", int(width/2)), "", 1, "C", false, 0, "")

	// Table Header
	pdf.SetFont("Helvetica", "B", 7)
	pdf.CellFormat(printableWd*0.15, 4, "Cant", "", 0, "L", false, 0, "")
	pdf.CellFormat(printableWd*0.45, 4, "Descripcion", "", 0, "L", false, 0, "")
	pdf.CellFormat(printableWd*0.20, 4, "P.Unit", "", 0, "R", false, 0, "")
	pdf.CellFormat(printableWd*0.20, 4, "Total", "", 1, "R", false, 0, "")

	// Items
	pdf.SetFont("Helvetica", "", 7)
	for _, item := range data.Items {
		desc := sanitize(item.Description)
		if len(desc) > 22 {
			desc = desc[:20] + ".."
		}
		pdf.CellFormat(printableWd*0.15, 4, fmt.Sprintf("%.0f", item.Quantity), "", 0, "L", false, 0, "")
		pdf.CellFormat(printableWd*0.45, 4, desc, "", 0, "L", false, 0, "")
		pdf.CellFormat(printableWd*0.20, 4, fmt.Sprintf("$%.2f", item.UnitPrice), "", 0, "R", false, 0, "")
		pdf.CellFormat(printableWd*0.20, 4, fmt.Sprintf("$%.2f", item.Total), "", 1, "R", false, 0, "")
	}

	// Separator
	pdf.CellFormat(printableWd, 3, strings.Repeat("-", int(width/2)), "", 1, "C", false, 0, "")

	// Totals
	pdf.SetFont("Helvetica", "B", 9)
	pdf.CellFormat(printableWd*0.6, 5, "TOTAL USD:", "", 0, "R", false, 0, "")
	pdf.CellFormat(printableWd*0.4, 5, fmt.Sprintf("$%.2f", data.TotalUSD), "", 1, "R", false, 0, "")

	if data.ExchangeRate > 0 {
		pdf.SetFont("Helvetica", "", 8)
		pdf.CellFormat(printableWd*0.6, 4, fmt.Sprintf("Tasa BCV (Bs/USD):"), "", 0, "R", false, 0, "")
		pdf.CellFormat(printableWd*0.4, 4, fmt.Sprintf("%.2f", data.ExchangeRate), "", 1, "R", false, 0, "")

		totalVES := data.TotalVES
		if totalVES <= 0 {
			totalVES = data.TotalUSD * data.ExchangeRate
		}
		pdf.SetFont("Helvetica", "B", 8)
		pdf.CellFormat(printableWd*0.6, 4, "TOTAL VES:", "", 0, "R", false, 0, "")
		pdf.CellFormat(printableWd*0.4, 4, fmt.Sprintf("Bs. %.2f", totalVES), "", 1, "R", false, 0, "")
	}

	// Payments section
	if len(data.Payments) > 0 {
		pdf.Ln(2)
		pdf.SetFont("Helvetica", "B", 7)
		pdf.CellFormat(printableWd, 4, "Formas de Pago:", "", 1, "L", false, 0, "")
		pdf.SetFont("Helvetica", "", 7)
		for _, p := range data.Payments {
			amt := fmt.Sprintf("$%.2f", p.AmountUSD)
			if p.AmountVES > 0 {
				amt += fmt.Sprintf(" (Bs. %.2f)", p.AmountVES)
			}
			pdf.CellFormat(printableWd*0.5, 4, sanitize(p.Method), "", 0, "L", false, 0, "")
			pdf.CellFormat(printableWd*0.5, 4, amt, "", 1, "R", false, 0, "")
		}
	}

	// Footer
	pdf.Ln(4)
	pdf.SetFont("Helvetica", "I", 7)
	pdf.CellFormat(printableWd, 4, "Gracias por su compra!", "", 1, "C", false, 0, "")
	if data.FooterNotes != "" {
		pdf.MultiCell(printableWd, 3, sanitize(data.FooterNotes), "", "C", false)
	}

	var buf bytes.Buffer
	err := pdf.Output(&buf)
	if err != nil {
		return nil, fmt.Errorf("failed to generate PDF: %w", err)
	}

	return buf.Bytes(), nil
}

// sanitize strips non-ASCII accents for default PDF latin fonts
func sanitize(s string) string {
	r := strings.NewReplacer(
		"á", "a", "é", "e", "í", "i", "ó", "o", "ú", "u",
		"Á", "A", "É", "E", "Í", "I", "Ó", "O", "Ú", "U",
		"ñ", "n", "Ñ", "N", "¿", "", "¡", "",
	)
	return r.Replace(s)
}
