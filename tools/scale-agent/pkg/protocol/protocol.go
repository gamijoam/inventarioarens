package protocol

import (
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"
)

type ScaleReading struct {
	Weight    float64   `json:"weight"`
	Unit      string    `json:"unit"`
	IsStable  bool      `json:"is_stable"`
	Raw       string    `json:"raw"`
	Timestamp time.Time `json:"timestamp"`
}

type Parser interface {
	Parse(raw string) (*ScaleReading, error)
}

// GenericParser handles standard continuous broadcast strings like:
// "ST,GS,+  1.250kg", "US,GS,+  0.840kg", "  2.500 kg", etc.
type GenericParser struct{}

var (
	// Matches weight float and optional unit from string
	genericRegex = regexp.MustCompile(`([+-]?\s*\d+\.?\d*)\s*(kg|lb|g)?`)
)

func (p *GenericParser) Parse(raw string) (*ScaleReading, error) {
	trimmed := strings.TrimSpace(raw)
	if trimmed == "" {
		return nil, fmt.Errorf("empty raw reading")
	}

	upper := strings.ToUpper(trimmed)
	isStable := true
	if strings.Contains(upper, "US") || strings.Contains(upper, "UNSTABLE") {
		isStable = false
	}

	matches := genericRegex.FindStringSubmatch(trimmed)
	if len(matches) < 2 {
		return nil, fmt.Errorf("could not parse weight from %q", raw)
	}

	weightStr := strings.ReplaceAll(matches[1], " ", "")
	weight, err := strconv.ParseFloat(weightStr, 64)
	if err != nil {
		return nil, fmt.Errorf("invalid weight numeric value %q: %w", weightStr, err)
	}

	unit := "kg"
	if len(matches) >= 3 && matches[2] != "" {
		unit = strings.ToLower(matches[2])
	}

	return &ScaleReading{
		Weight:    weight,
		Unit:      unit,
		IsStable:  isStable,
		Raw:       trimmed,
		Timestamp: time.Now(),
	}, nil
}

// TorreyParser handles Torrey scale strings like:
// "01.345\r" or "P03.450\r"
type TorreyParser struct{}

var torreyRegex = regexp.MustCompile(`^P?(\d+\.\d{2,3})`)

func (p *TorreyParser) Parse(raw string) (*ScaleReading, error) {
	trimmed := strings.TrimSpace(raw)
	matches := torreyRegex.FindStringSubmatch(trimmed)
	if len(matches) < 2 {
		return nil, fmt.Errorf("could not parse Torrey weight from %q", raw)
	}

	weight, err := strconv.ParseFloat(matches[1], 64)
	if err != nil {
		return nil, fmt.Errorf("invalid Torrey weight value %q: %w", matches[1], err)
	}

	return &ScaleReading{
		Weight:    weight,
		Unit:      "kg",
		IsStable:  true,
		Raw:       trimmed,
		Timestamp: time.Now(),
	}, nil
}
