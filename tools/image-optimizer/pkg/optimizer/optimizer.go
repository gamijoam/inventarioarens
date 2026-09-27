package optimizer

import (
	"bytes"
	"fmt"
	"image"
	"image/jpeg"
	"image/png"
	"io"
	"os"
	"path/filepath"

	"golang.org/x/image/draw"
)

type Options struct {
	MaxWidth  int
	MaxHeight int
	Quality   int
	Format    string // "jpeg", "png", "auto"
}

type Result struct {
	OriginalWidth  int     `json:"original_width"`
	OriginalHeight int     `json:"original_height"`
	Width          int     `json:"width"`
	Height         int     `json:"height"`
	BytesIn        int64   `json:"bytes_in"`
	BytesOut       int64   `json:"bytes_out"`
	Ratio          float64 `json:"ratio"`
}

type Optimizer struct{}

func NewOptimizer() *Optimizer {
	return &Optimizer{}
}

func (o *Optimizer) OptimizeImage(src io.Reader, dst io.Writer, opts Options) (Result, error) {
	if opts.Quality <= 0 || opts.Quality > 100 {
		opts.Quality = 80
	}
	if opts.MaxWidth <= 0 {
		opts.MaxWidth = 1200
	}

	rawBytes, err := io.ReadAll(src)
	if err != nil {
		return Result{}, fmt.Errorf("failed to read source image: %w", err)
	}

	bytesIn := int64(len(rawBytes))

	img, format, err := image.Decode(bytes.NewReader(rawBytes))
	if err != nil {
		return Result{}, fmt.Errorf("failed to decode image: %w", err)
	}

	bounds := img.Bounds()
	origW := bounds.Dx()
	origH := bounds.Dy()

	targetW := origW
	targetH := origH

	// Calculate scaling preserving aspect ratio
	if origW > opts.MaxWidth {
		targetW = opts.MaxWidth
		targetH = int(float64(origH) * (float64(opts.MaxWidth) / float64(origW)))
	}
	if opts.MaxHeight > 0 && targetH > opts.MaxHeight {
		targetW = int(float64(targetW) * (float64(opts.MaxHeight) / float64(targetH)))
		targetH = opts.MaxHeight
	}

	var finalImg image.Image = img
	if targetW != origW || targetH != origH {
		scaled := image.NewRGBA(image.Rect(0, 0, targetW, targetH))
		draw.BiLinear.Scale(scaled, scaled.Bounds(), img, bounds, draw.Over, nil)
		finalImg = scaled
	}

	var buf bytes.Buffer
	outFormat := opts.Format
	if outFormat == "" || outFormat == "auto" {
		outFormat = format
	}

	if outFormat == "png" {
		err = png.Encode(&buf, finalImg)
	} else {
		err = jpeg.Encode(&buf, finalImg, &jpeg.Options{Quality: opts.Quality})
	}
	if err != nil {
		return Result{}, fmt.Errorf("failed to encode optimized image: %w", err)
	}

	bytesOut := int64(buf.Len())
	if _, err := buf.WriteTo(dst); err != nil {
		return Result{}, fmt.Errorf("failed to write destination image: %w", err)
	}

	ratio := 0.0
	if bytesIn > 0 {
		ratio = float64(bytesOut) / float64(bytesIn)
	}

	return Result{
		OriginalWidth:  origW,
		OriginalHeight: origH,
		Width:          targetW,
		Height:         targetH,
		BytesIn:        bytesIn,
		BytesOut:       bytesOut,
		Ratio:          ratio,
	}, nil
}

func (o *Optimizer) OptimizeFile(srcPath, dstPath string, opts Options) (Result, error) {
	data, err := os.ReadFile(srcPath)
	if err != nil {
		return Result{}, err
	}

	if err := os.MkdirAll(filepath.Dir(dstPath), 0755); err != nil {
		return Result{}, err
	}

	var buf bytes.Buffer
	res, err := o.OptimizeImage(bytes.NewReader(data), &buf, opts)
	if err != nil {
		return Result{}, err
	}

	if err := os.WriteFile(dstPath, buf.Bytes(), 0644); err != nil {
		return Result{}, err
	}

	return res, nil
}
