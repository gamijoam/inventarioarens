package optimizer

import (
	"bytes"
	"image"
	"image/color"
	"image/draw"
	"image/jpeg"
	"testing"
)

// Helper to create a dummy test JPEG image
func createTestImage(width, height int) []byte {
	img := image.NewRGBA(image.Rect(0, 0, width, height))
	// Fill with colors
	draw.Draw(img, img.Bounds(), &image.Uniform{C: color.RGBA{R: 200, G: 50, B: 50, A: 255}}, image.Point{}, draw.Src)

	var buf bytes.Buffer
	_ = jpeg.Encode(&buf, img, &jpeg.Options{Quality: 95})
	return buf.Bytes()
}

func TestOptimizeImage_ResizesWhenExceedsMaxWidth(t *testing.T) {
	raw := createTestImage(1600, 1200)

	opt := NewOptimizer()
	opts := Options{
		MaxWidth: 800,
		Quality:  80,
	}

	var out bytes.Buffer
	res, err := opt.OptimizeImage(bytes.NewReader(raw), &out, opts)
	if err != nil {
		t.Fatalf("Unexpected error: %v", err)
	}

	if res.OriginalWidth != 1600 || res.OriginalHeight != 1200 {
		t.Errorf("Expected original 1600x1200, got %dx%d", res.OriginalWidth, res.OriginalHeight)
	}

	if res.Width != 800 || res.Height != 600 {
		t.Errorf("Expected resized 800x600, got %dx%d", res.Width, res.Height)
	}

	if res.BytesOut >= res.BytesIn {
		t.Errorf("Expected compression (BytesOut < BytesIn), got in=%d, out=%d", res.BytesIn, res.BytesOut)
	}
}

func TestOptimizeImage_DoesNotUpscaleSmallerImages(t *testing.T) {
	raw := createTestImage(400, 300)

	opt := NewOptimizer()
	opts := Options{
		MaxWidth: 800,
		Quality:  80,
	}

	var out bytes.Buffer
	res, err := opt.OptimizeImage(bytes.NewReader(raw), &out, opts)
	if err != nil {
		t.Fatalf("Unexpected error: %v", err)
	}

	if res.Width != 400 || res.Height != 300 {
		t.Errorf("Expected untouched dimensions 400x300, got %dx%d", res.Width, res.Height)
	}
}
