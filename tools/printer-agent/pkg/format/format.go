package format

import (
	"fmt"
	"math"
	"regexp"
	"strings"
	"time"
)

var accentReplacer = strings.NewReplacer(
	"á", "a", "é", "e", "í", "i", "ó", "o", "ú", "u",
	"Á", "A", "É", "E", "Í", "I", "Ó", "O", "Ú", "U",
	"ñ", "n", "Ñ", "N",
	"ü", "u", "Ü", "U", "ç", "c", "Ç", "C",
	"à", "a", "è", "e", "ì", "i", "ò", "o", "ù", "u",
	"¿", "?", "¡", "!", "€", "EUR", "—", "-", "–", "-",
)

var controlCharsRegex = regexp.MustCompile(`[\x00-\x08\x0B-\x1F\x7F]`)

// Sanitize cleans text for generic thermal printers:
// - Converts common accents to ASCII
// - Removes non-printable control characters (except \t and \n)
// - Truncates lines exceeding 64 chars
func Sanitize(text string) string {
	replaced := accentReplacer.Replace(text)
	lines := strings.Split(replaced, "\n")
	var out []string

	for _, line := range lines {
		clean := controlCharsRegex.ReplaceAllString(line, "")
		runes := []rune(clean)
		if len(runes) > 64 {
			clean = string(runes[:61]) + "..."
		}
		out = append(out, clean)
	}

	return strings.Join(out, "\n") + "\n"
}

func formatUSD(val float64) string {
	return fmt.Sprintf("$%.2f", val)
}

func formatVES(val float64) string {
	// Venezuelan bolivars format: Bs 1.234,56
	neg := val < 0
	val = math.Abs(val)
	intPart := int64(val)
	decPart := int64(math.Round((val-float64(intPart))*100)) % 100

	intStr := fmt.Sprintf("%d", intPart)
	// Add dot thousands separator
	var formattedInt strings.Builder
	l := len(intStr)
	for i, c := range intStr {
		if i > 0 && (l-i)%3 == 0 {
			formattedInt.WriteRune('.')
		}
		formattedInt.WriteRune(c)
	}

	prefix := ""
	if neg {
		prefix = "-"
	}
	return fmt.Sprintf("%sBs %s,%02d", prefix, formattedInt.String(), decPart)
}

func getFloat(m map[string]interface{}, key string) float64 {
	if val, ok := m[key]; ok {
		switch v := val.(type) {
		case float64:
			return v
		case float32:
			return float64(v)
		case int:
			return float64(v)
		case int64:
			return float64(v)
		}
	}
	return 0
}

func getString(m map[string]interface{}, key string) string {
	if val, ok := m[key]; ok && val != nil {
		return fmt.Sprintf("%v", val)
	}
	return ""
}

func getBool(m map[string]interface{}, key string, def bool) bool {
	if val, ok := m[key]; ok && val != nil {
		if b, ok := val.(bool); ok {
			return b
		}
	}
	return def
}

func getMap(m map[string]interface{}, key string) map[string]interface{} {
	if val, ok := m[key]; ok && val != nil {
		if sub, ok := val.(map[string]interface{}); ok {
			return sub
		}
	}
	return map[string]interface{}{}
}

func getSlice(m map[string]interface{}, key string) []interface{} {
	if val, ok := m[key]; ok && val != nil {
		if s, ok := val.([]interface{}); ok {
			return s
		}
	}
	return nil
}

// BuildPlainTicket converts a ticket JSON map into formatted plain text
func BuildPlainTicket(ticket map[string]interface{}) string {
	profile := getMap(ticket, "profile")
	tenant := getMap(ticket, "tenant")
	posOrder := getMap(ticket, "pos_order")
	totals := getMap(ticket, "totals")

	width := int(getFloat(profile, "paper_width_mm"))
	if width == 0 {
		width = 80
	}
	max := 48
	if width == 58 {
		max = 32
	}

	var lines []string

	// Header
	header := getString(profile, "logo_text")
	if header == "" {
		header = getString(tenant, "name")
	}
	if header != "" {
		lines = append(lines, strings.ToUpper(header))
	}

	headerText := getString(profile, "header_text")
	if headerText != "" {
		lines = append(lines, headerText)
	}

	if getBool(profile, "show_tenant_slug", true) {
		slug := getString(tenant, "slug")
		if slug != "" {
			lines = append(lines, slug)
		}
	}

	orderID := getString(posOrder, "id")
	if orderID == "" {
		orderID = "?"
	}
	lines = append(lines, fmt.Sprintf("Ticket POS #%s", orderID))

	if getBool(profile, "show_sale_number", true) {
		saleID := getString(posOrder, "sale_id")
		if saleID != "" {
			lines = append(lines, fmt.Sprintf("Venta #%s", saleID))
		}
	}

	if getBool(profile, "show_paid_at", true) {
		paidAt := getString(posOrder, "paid_at")
		if paidAt != "" {
			t, err := time.Parse(time.RFC3339, paidAt)
			if err == nil {
				// America/Caracas timezone UTC-4
				loc := time.FixedZone("VET", -4*3600)
				lines = append(lines, fmt.Sprintf("Fecha: %s", t.In(loc).Format("02/01/2006 03:04 PM")))
			} else {
				lines = append(lines, fmt.Sprintf("Fecha: %s", paidAt))
			}
		}
	}

	if getBool(profile, "show_cashier", true) {
		cashier := getString(posOrder, "cashier_name")
		if cashier != "" {
			lines = append(lines, fmt.Sprintf("Cajero: %s", cashier))
		}
	}

	if getBool(profile, "show_cash_register", true) {
		register := getString(posOrder, "cash_register_name")
		if register != "" {
			lines = append(lines, fmt.Sprintf("Caja: %s", register))
		}
	}

	if getBool(profile, "show_branch", true) {
		branch := getString(posOrder, "branch_name")
		if branch != "" {
			lines = append(lines, fmt.Sprintf("Sucursal: %s", branch))
		}
	}

	if getBool(profile, "show_customer", true) {
		custName := getString(posOrder, "customer_name")
		if custName == "" {
			custName = "Consumidor Final"
		}
		lines = append(lines, fmt.Sprintf("Cliente: %s", custName))

		doc := getString(posOrder, "customer_document")
		if doc != "" {
			lines = append(lines, fmt.Sprintf("CI/RIF: %s", doc))
		}

		phone := getString(posOrder, "customer_phone")
		if phone != "" {
			lines = append(lines, fmt.Sprintf("Telf: %s", phone))
		}
	}

	// Promotions
	promotions := getSlice(ticket, "promotions")
	if len(promotions) > 0 {
		lines = append(lines, strings.Repeat("-", max))
		lines = append(lines, "PROMOCIONES")
		for _, p := range promotions {
			if pm, ok := p.(map[string]interface{}); ok {
				lbl := getString(pm, "label")
				if lbl == "" {
					lbl = "PROMOCION"
				}
				pName := getString(pm, "promotion_name")
				if pName == "" {
					pName = "Promoción"
				}
				lines = append(lines, fmt.Sprintf("%s: %s", lbl, pName))
			}
		}
	}

	// Items
	lines = append(lines, strings.Repeat("-", max))
	items := getSlice(ticket, "items")
	for _, itemRaw := range items {
		if item, ok := itemRaw.(map[string]interface{}); ok {
			pName := getString(item, "product_name")
			if pName == "" {
				pName = "Producto"
			}
			lines = append(lines, pName)

			if getBool(profile, "show_item_sku", true) {
				sku := getString(item, "sku")
				if sku != "" {
					lines = append(lines, "  "+sku)
				}
			}

			pLabels := getSlice(item, "promotion_labels")
			for _, lbl := range pLabels {
				if s := fmt.Sprintf("%v", lbl); s != "" {
					lines = append(lines, "  "+s)
				}
			}

			qty := getFloat(item, "quantity")
			unit := getFloat(item, "unit_price")
			total := getFloat(item, "total")
			lines = append(lines, fmt.Sprintf("  %.2g x %s = %s", qty, formatUSD(unit), formatUSD(total)))

			if getBool(profile, "show_item_discount", true) {
				disc := getFloat(item, "discount")
				if disc > 0 {
					lines = append(lines, fmt.Sprintf("  Desc: %s", formatUSD(disc)))
				}
			}

			if getBool(profile, "show_item_serials", true) {
				serials := getSlice(item, "serials")
				for _, sRaw := range serials {
					if sm, ok := sRaw.(map[string]interface{}); ok {
						sn := getString(sm, "serial_number")
						if sn != "" {
							lines = append(lines, fmt.Sprintf("  IMEI/Serial: %s", sn))
						}
					}
				}
			}

			if getBool(profile, "show_warranty_summary", true) {
				w := getMap(item, "warranty")
				wName := getString(w, "name")
				if wName != "" {
					wLine := fmt.Sprintf("  Garantia: %s", wName)
					if days := getString(w, "duration_days"); days != "" {
						wLine += fmt.Sprintf(" - %s dias", days)
					}
					if exp := getString(w, "expires_at"); exp != "" {
						wLine += fmt.Sprintf(" - vence %s", exp)
					}
					lines = append(lines, wLine)
				}
			}
		}
	}

	// Totals
	lines = append(lines, strings.Repeat("-", max))
	lines = append(lines, fmt.Sprintf("Total USD: %s", formatUSD(getFloat(totals, "total_base_amount"))))
	if getBool(profile, "show_total_local", true) {
		lines = append(lines, fmt.Sprintf("Total VES: %s", formatVES(getFloat(totals, "total_local_amount"))))
	}
	lines = append(lines, fmt.Sprintf("Pagado USD: %s", formatUSD(getFloat(totals, "paid_base_amount"))))
	if getBool(profile, "show_receivable_balance", true) {
		bal := getFloat(totals, "balance_base_amount")
		if bal > 0 {
			lines = append(lines, fmt.Sprintf("Saldo CxC: %s", formatUSD(bal)))
		}
	}

	// Payments
	lines = append(lines, strings.Repeat("-", max))
	payments := getSlice(ticket, "payments")
	for _, pRaw := range payments {
		if p, ok := pRaw.(map[string]interface{}); ok {
			method := getString(p, "method")
			currency := getString(p, "currency")
			if currency == "" {
				currency = "USD"
			}
			amount := getFloat(p, "amount")
			var amtFormatted string
			if currency == "VES" {
				amtFormatted = formatVES(amount)
			} else {
				amtFormatted = formatUSD(amount)
			}
			lines = append(lines, fmt.Sprintf("%s %s: %s", method, currency, amtFormatted))

			if getBool(profile, "show_payment_rate", true) {
				rate := getFloat(p, "exchange_rate")
				if rate > 0 {
					rateType := getString(p, "exchange_rate_type_code")
					lines = append(lines, fmt.Sprintf("  %s @ %.2f", rateType, rate))
				}
			}

			if getBool(profile, "show_payment_reference", true) {
				ref := getString(p, "reference")
				if ref != "" {
					lines = append(lines, fmt.Sprintf("  Ref: %s", ref))
				}
			}
		}
	}

	// Warranty & Footer
	warrantyText := getString(profile, "warranty_policy_text")
	if warrantyText != "" {
		lines = append(lines, strings.Repeat("-", max))
		lines = append(lines, warrantyText)
	}

	footerText := getString(profile, "footer_text")
	if footerText != "" {
		lines = append(lines, strings.Repeat("-", max))
		lines = append(lines, footerText)
	}

	if getBool(profile, "show_non_fiscal_text", true) {
		legal := getString(profile, "legal_text")
		if legal == "" {
			legal = "Documento no fiscal"
		}
		lines = append(lines, legal)
	}

	// Clamp line length
	for i, l := range lines {
		runes := []rune(l)
		if len(runes) > max {
			cutoff := max - 3
			if cutoff < 1 {
				cutoff = 1
			}
			lines[i] = string(runes[:cutoff]) + "..."
		}
	}

	return strings.Join(lines, "\n")
}

// BuildPlainReportZ converts a report Z JSON map into formatted plain text
func BuildPlainReportZ(ticket map[string]interface{}) string {
	profile := getMap(ticket, "profile")
	totals := getMap(ticket, "totals")

	width := int(getFloat(profile, "paper_width_mm"))
	if width == 0 {
		width = 58
	}
	max := 48
	if width == 58 {
		max = 32
	}

	var lines []string

	header := getString(profile, "logo_text")
	if header == "" {
		tenant := getMap(ticket, "tenant")
		header = getString(tenant, "name")
	}
	if header != "" {
		lines = append(lines, strings.ToUpper(header))
	}

	lines = append(lines, "REPORTE Z")
	zNum := getString(ticket, "z_number")
	if zNum == "" {
		zNum = "?"
	}
	lines = append(lines, fmt.Sprintf("Z #%s", zNum))
	lines = append(lines, strings.Repeat("-", max))

	register := getString(ticket, "cash_register")
	if register == "" {
		register = "-"
	}
	lines = append(lines, fmt.Sprintf("Caja: %s", register))

	branch := getString(ticket, "branch")
	if branch == "" {
		branch = "-"
	}
	lines = append(lines, fmt.Sprintf("Sucursal: %s", branch))

	cashier := getString(ticket, "cashier")
	if cashier == "" {
		cashier = "-"
	}
	lines = append(lines, fmt.Sprintf("Cajero: %s", cashier))

	openAt := getString(ticket, "opened_at")
	if openAt == "" {
		openAt = "-"
	}
	lines = append(lines, fmt.Sprintf("Apertura: %s", openAt))

	closeAt := getString(ticket, "closed_at")
	if closeAt == "" {
		closeAt = "-"
	}
	lines = append(lines, fmt.Sprintf("Cierre: %s", closeAt))

	lines = append(lines, strings.Repeat("-", max))
	lines = append(lines, fmt.Sprintf("Tickets: %d", int(getFloat(totals, "orders_count"))))
	lines = append(lines, fmt.Sprintf("Total USD: %s", formatUSD(getFloat(totals, "paid_base_amount"))))
	lines = append(lines, fmt.Sprintf("Total VES: %s", formatVES(getFloat(totals, "paid_local_amount"))))

	lines = append(lines, strings.Repeat("-", max))
	payments := getSlice(ticket, "payments")
	if len(payments) == 0 {
		lines = append(lines, "Sin pagos registrados.")
	} else {
		for _, pRaw := range payments {
			if p, ok := pRaw.(map[string]interface{}); ok {
				pName := getString(p, "name")
				if pName == "" {
					pName = getString(p, "method")
				}
				if pName == "" {
					pName = "Pago"
				}
				currency := getString(p, "currency")
				if currency == "" {
					currency = "USD"
				}
				var amtStr string
				if currency == "VES" {
					amtStr = formatVES(getFloat(p, "amount_local"))
				} else {
					amtStr = formatUSD(getFloat(p, "amount_base"))
				}
				lines = append(lines, fmt.Sprintf("%s: %s", pName, amtStr))
				if rate := getFloat(p, "exchange_rate"); rate > 0 {
					lines = append(lines, fmt.Sprintf("  tasa @ %.2f", rate))
				}
			}
		}
	}

	lines = append(lines, strings.Repeat("-", max))
	lines = append(lines, fmt.Sprintf("Dif efectivo USD: %s", formatUSD(getFloat(totals, "difference_cash_usd"))))
	lines = append(lines, fmt.Sprintf("Dif efectivo VES: %s", formatVES(getFloat(totals, "difference_cash_ves"))))

	footer := getString(profile, "footer_text")
	if footer != "" {
		lines = append(lines, strings.Repeat("-", max))
		lines = append(lines, footer)
	}

	if getBool(profile, "show_non_fiscal_text", true) {
		legal := getString(profile, "legal_text")
		if legal == "" {
			legal = "Documento no fiscal"
		}
		lines = append(lines, legal)
	}

	for i, l := range lines {
		runes := []rune(l)
		if len(runes) > max {
			cutoff := max - 3
			if cutoff < 1 {
				cutoff = 1
			}
			lines[i] = string(runes[:cutoff]) + "..."
		}
	}

	return strings.Join(lines, "\n")
}
