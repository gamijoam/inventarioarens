package invoice

import (
	"bytes"
	"fmt"
	"strings"

	"github.com/jung-kurt/gofpdf"
)

type InvoiceItem struct {
	SKU         string  `json:"sku"`
	Description string  `json:"description"`
	Quantity    float64 `json:"quantity"`
	UnitPrice   float64 `json:"unit_price"`
	Total       float64 `json:"total"`
}

type InvoiceData struct {
	CompanyName  string        `json:"company_name"`
	CompanyRif   string        `json:"company_rif"`
	Address      string        `json:"address"`
	Phone        string        `json:"phone"`
	InvoiceNo    string        `json:"invoice_no"`
	Date         string        `json:"date"`
	DueDate      string        `json:"due_date"`
	CustomerName string        `json:"customer_name"`
	CustomerRif  string        `json:"customer_rif"`
	CustomerAddr string        `json:"customer_addr"`
	Items        []InvoiceItem `json:"items"`
	SubtotalUSD  float64       `json:"subtotal_usd"`
	TaxUSD       float64       `json:"tax_usd"`
	TotalUSD     float64       `json:"total_usd"`
	ExchangeRate float64       `json:"exchange_rate"`
	TotalVES     float64       `json:"total_ves"`
	Notes        string        `json:"notes"`
}

type InvoiceGenerator struct{}

func NewInvoiceGenerator() *InvoiceGenerator {
	return &InvoiceGenerator{}
}

func (g *InvoiceGenerator) GeneratePDF(data InvoiceData) ([]byte, error) {
	pdf := gofpdf.New("P", "mm", "Letter", "")
	pdf.SetMargins(15, 15, 15)
	pdf.SetAutoPageBreak(true, 15)
	pdf.AddPage()

	pageWd := 186.0 // 216 - 30 margin

	// 1. Header (Company vs Invoice No)
	pdf.SetFont("Helvetica", "B", 14)
	pdf.CellFormat(110, 6, sanitize(data.CompanyName), "", 0, "L", false, 0, "")
	pdf.SetFont("Helvetica", "B", 12)
	pdf.SetTextColor(40, 70, 150)
	pdf.CellFormat(76, 6, "FACTURA DE VENTA", "", 1, "R", false, 0, "")
	pdf.SetTextColor(0, 0, 0)

	pdf.SetFont("Helvetica", "", 9)
	pdf.CellFormat(110, 4, "RIF: "+sanitize(data.CompanyRif), "", 0, "L", false, 0, "")
	pdf.SetFont("Helvetica", "B", 10)
	pdf.CellFormat(76, 4, "Nro: "+sanitize(data.InvoiceNo), "", 1, "R", false, 0, "")

	pdf.SetFont("Helvetica", "", 8)
	if data.Address != "" {
		pdf.CellFormat(110, 4, sanitize(data.Address), "", 0, "L", false, 0, "")
	} else {
		pdf.Cell(110, 4, "")
	}
	pdf.CellFormat(76, 4, "Fecha: "+data.Date, "", 1, "R", false, 0, "")

	if data.Phone != "" {
		pdf.CellFormat(110, 4, "Tlf: "+sanitize(data.Phone), "", 0, "L", false, 0, "")
	} else {
		pdf.Cell(110, 4, "")
	}
	if data.DueDate != "" {
		pdf.CellFormat(76, 4, "Vencimiento: "+data.DueDate, "", 1, "R", false, 0, "")
	} else {
		pdf.Ln(4)
	}

	pdf.Ln(4)

	// 2. Customer Box
	pdf.SetFillColor(245, 247, 250)
	pdf.Rect(15, pdf.GetY(), pageWd, 18, "F")
	pdf.SetFont("Helvetica", "B", 8)
	pdf.SetXY(18, pdf.GetY()+2)
	pdf.CellFormat(30, 4, "CLIENTE:", "", 0, "L", false, 0, "")
	pdf.SetFont("Helvetica", "", 8)
	pdf.CellFormat(130, 4, sanitize(data.CustomerName), "", 1, "L", false, 0, "")

	pdf.SetX(18)
	pdf.SetFont("Helvetica", "B", 8)
	pdf.CellFormat(30, 4, "RIF / DOC:", "", 0, "L", false, 0, "")
	pdf.SetFont("Helvetica", "", 8)
	pdf.CellFormat(130, 4, sanitize(data.CustomerRif), "", 1, "L", false, 0, "")

	pdf.SetX(18)
	pdf.SetFont("Helvetica", "B", 8)
	pdf.CellFormat(30, 4, "DIRECCION:", "", 0, "L", false, 0, "")
	pdf.SetFont("Helvetica", "", 8)
	pdf.CellFormat(130, 4, sanitize(data.CustomerAddr), "", 1, "L", false, 0, "")

	pdf.SetY(pdf.GetY() + 6)

	// 3. Table Header
	pdf.SetFillColor(50, 75, 120)
	pdf.SetTextColor(255, 255, 255)
	pdf.SetFont("Helvetica", "B", 8)

	pdf.CellFormat(25, 6, "CODIGO", "1", 0, "C", true, 0, "")
	pdf.CellFormat(95, 6, "DESCRIPCION", "1", 0, "L", true, 0, "")
	pdf.CellFormat(20, 6, "CANT", "1", 0, "C", true, 0, "")
	pdf.CellFormat(23, 6, "P.UNIT ($)", "1", 0, "R", true, 0, "")
	pdf.CellFormat(23, 6, "TOTAL ($)", "1", 1, "R", true, 0, "")

	pdf.SetTextColor(0, 0, 0)
	pdf.SetFont("Helvetica", "", 8)

	// Items rows
	fill := false
	for _, it := range data.Items {
		if fill {
			pdf.SetFillColor(248, 249, 251)
		} else {
			pdf.SetFillColor(255, 255, 255)
		}

		desc := sanitize(it.Description)
		if len(desc) > 60 {
			desc = desc[:57] + "..."
		}

		pdf.CellFormat(25, 5, sanitize(it.SKU), "1", 0, "C", true, 0, "")
		pdf.CellFormat(95, 5, " "+desc, "1", 0, "L", true, 0, "")
		pdf.CellFormat(20, 5, fmt.Sprintf("%.2f", it.Quantity), "1", 0, "C", true, 0, "")
		pdf.CellFormat(23, 5, fmt.Sprintf("%.2f", it.UnitPrice), "1", 0, "R", true, 0, "")
		pdf.CellFormat(23, 5, fmt.Sprintf("%.2f", it.Total), "1", 1, "R", true, 0, "")

		fill = !fill
	}

	pdf.Ln(4)

	// 4. Totals Block
	totX := 15 + pageWd - 75
	pdf.SetX(totX)
	pdf.SetFont("Helvetica", "", 8)
	pdf.CellFormat(40, 5, "SUBTOTAL USD:", "1", 0, "R", false, 0, "")
	pdf.CellFormat(35, 5, fmt.Sprintf("$ %.2f", data.SubtotalUSD), "1", 1, "R", false, 0, "")

	if data.TaxUSD > 0 {
		pdf.SetX(totX)
		pdf.CellFormat(40, 5, "IVA (16%):", "1", 0, "R", false, 0, "")
		pdf.CellFormat(35, 5, fmt.Sprintf("$ %.2f", data.TaxUSD), "1", 1, "R", false, 0, "")
	}

	pdf.SetX(totX)
	pdf.SetFont("Helvetica", "B", 9)
	pdf.SetFillColor(230, 240, 255)
	pdf.CellFormat(40, 6, "TOTAL USD:", "1", 0, "R", true, 0, "")
	pdf.CellFormat(35, 6, fmt.Sprintf("$ %.2f", data.TotalUSD), "1", 1, "R", true, 0, "")

	if data.ExchangeRate > 0 {
		pdf.SetX(totX)
		pdf.SetFont("Helvetica", "", 8)
		pdf.CellFormat(40, 5, fmt.Sprintf("Tasa BCV:"), "1", 0, "R", false, 0, "")
		pdf.CellFormat(35, 5, fmt.Sprintf("%.2f Bs/$", data.ExchangeRate), "1", 1, "R", false, 0, "")

		totalVES := data.TotalVES
		if totalVES <= 0 {
			totalVES = data.TotalUSD * data.ExchangeRate
		}
		pdf.SetX(totX)
		pdf.SetFont("Helvetica", "B", 9)
		pdf.SetFillColor(230, 255, 235)
		pdf.CellFormat(40, 6, "TOTAL VES:", "1", 0, "R", true, 0, "")
		pdf.CellFormat(35, 6, fmt.Sprintf("Bs. %.2f", totalVES), "1", 1, "R", true, 0, "")
	}

	var buf bytes.Buffer
	err := pdf.Output(&buf)
	if err != nil {
		return nil, fmt.Errorf("failed to generate invoice PDF: %w", err)
	}

	return buf.Bytes(), nil
}

func sanitize(s string) string {
	r := strings.NewReplacer(
		"á", "a", "é", "e", "í", "i", "ó", "o", "ú", "u",
		"Á", "A", "É", "E", "Í", "I", "Ó", "O", "Ú", "U",
		"ñ", "n", "Ñ", "N", "¿", "", "¡", "",
	)
	return r.Replace(s)
}
