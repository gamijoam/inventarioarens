package barcode

import (
	"bytes"
	"image/png"
	"testing"
)

func TestGenerateCode128_ReturnsValidPNG(t *testing.T) {
	b, err := GenerateCode128("HARINA-PAN-01", 300, 100)
	if err != nil {
		t.Fatalf("Unexpected error: %v", err)
	}

	if len(b) < 100 {
		t.Fatalf("Image data too small (%d bytes)", len(b))
	}

	img, err := png.Decode(bytes.NewReader(b))
	if err != nil {
		t.Fatalf("Failed to decode PNG: %v", err)
	}

	if img.Bounds().Dx() != 300 || img.Bounds().Dy() != 100 {
		t.Errorf("Expected dimensions 300x100, got %dx%d", img.Bounds().Dx(), img.Bounds().Dy())
	}
}

func TestGenerateQRCode_ReturnsValidPNG(t *testing.T) {
	b, err := GenerateQRCode("https://app.balanzapro.com/p/12345", 200)
	if err != nil {
		t.Fatalf("Unexpected error: %v", err)
	}

	img, err := png.Decode(bytes.NewReader(b))
	if err != nil {
		t.Fatalf("Failed to decode PNG: %v", err)
	}

	if img.Bounds().Dx() != 200 || img.Bounds().Dy() != 200 {
		t.Errorf("Expected dimensions 200x200, got %dx%d", img.Bounds().Dx(), img.Bounds().Dy())
	}
}

func TestGenerateScaleBarcode_FormatsEAN13Correctly(t *testing.T) {
	// Item code: 42, weight: 1.250 kg (1250g)
	// Base without check digit: "20" + "00042" + "01250" = 12 digits
	ean, imgBytes, err := GenerateScaleBarcode("42", 1250, 300, 100)
	if err != nil {
		t.Fatalf("Unexpected error: %v", err)
	}

	if len(ean) != 13 {
		t.Errorf("Expected 13-digit EAN code, got %s (len %d)", ean, len(ean))
	}

	if ean[:2] != "20" {
		t.Errorf("Expected prefix 20, got %s", ean[:2])
	}

	if len(imgBytes) < 100 {
		t.Errorf("PNG bytes too small: %d", len(imgBytes))
	}
}
