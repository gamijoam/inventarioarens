package barcode

import (
	"bytes"
	"fmt"
	"image/png"
	"strconv"

	"github.com/boombuler/barcode"
	"github.com/boombuler/barcode/code128"
	"github.com/boombuler/barcode/ean"
	"github.com/boombuler/barcode/qr"
)

// GenerateCode128 generates Code128 barcode as PNG bytes.
func GenerateCode128(content string, width, height int) ([]byte, error) {
	bc, err := code128.Encode(content)
	if err != nil {
		return nil, fmt.Errorf("failed to encode code128: %w", err)
	}

	scaled, err := barcode.Scale(bc, width, height)
	if err != nil {
		return nil, fmt.Errorf("failed to scale barcode: %w", err)
	}

	var buf bytes.Buffer
	if err := png.Encode(&buf, scaled); err != nil {
		return nil, fmt.Errorf("failed to encode PNG: %w", err)
	}

	return buf.Bytes(), nil
}

// GenerateQRCode generates a QR Code as PNG bytes.
func GenerateQRCode(content string, size int) ([]byte, error) {
	qrCode, err := qr.Encode(content, qr.M, qr.Auto)
	if err != nil {
		return nil, fmt.Errorf("failed to encode QR code: %w", err)
	}

	scaled, err := barcode.Scale(qrCode, size, size)
	if err != nil {
		return nil, fmt.Errorf("failed to scale QR code: %w", err)
	}

	var buf bytes.Buffer
	if err := png.Encode(&buf, scaled); err != nil {
		return nil, fmt.Errorf("failed to encode PNG: %w", err)
	}

	return buf.Bytes(), nil
}

// CalculateEAN13Checksum computes the modulo-10 check digit for a 12-digit string.
func CalculateEAN13Checksum(first12 string) int {
	if len(first12) != 12 {
		return 0
	}

	sum := 0
	for i, ch := range first12 {
		val := int(ch - '0')
		if i%2 == 0 {
			sum += val * 1 // Odd positions (1-indexed)
		} else {
			sum += val * 3 // Even positions (1-indexed)
		}
	}

	check := (10 - (sum % 10)) % 10
	return check
}

// GenerateScaleBarcode formats a standard in-store scale barcode (prefix 20 + 5-digit item + 5-digit weight in grams + check digit)
// and returns both the 13-digit EAN string and its PNG image bytes.
func GenerateScaleBarcode(itemCode string, weightGrams int, width, height int) (string, []byte, error) {
	itemNum, _ := strconv.Atoi(itemCode)
	if itemNum > 99999 {
		itemNum %= 100000
	}
	if weightGrams > 99999 {
		weightGrams = 99999
	}
	if weightGrams < 0 {
		weightGrams = 0
	}

	// 12 digits: "20" (2 digits) + 5 digits item + 5 digits weight
	first12 := fmt.Sprintf("20%05d%05d", itemNum, weightGrams)
	checksum := CalculateEAN13Checksum(first12)
	fullEAN := fmt.Sprintf("%s%d", first12, checksum)

	bc, err := ean.Encode(fullEAN)
	if err != nil {
		return fullEAN, nil, fmt.Errorf("failed to encode EAN-13: %w", err)
	}

	scaled, err := barcode.Scale(bc, width, height)
	if err != nil {
		return fullEAN, nil, fmt.Errorf("failed to scale EAN-13: %w", err)
	}

	var buf bytes.Buffer
	if err := png.Encode(&buf, scaled); err != nil {
		return fullEAN, nil, fmt.Errorf("failed to encode PNG: %w", err)
	}

	return fullEAN, buf.Bytes(), nil
}
