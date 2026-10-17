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

// GenericParser handles common serial/TCP text output. The configured decimal
// separator resolves ambiguous values such as "1,250" for the device profile.
type GenericParser struct {
	DecimalSeparator string
	DefaultUnit      string
}

var genericRegex = regexp.MustCompile(`(?i)(?:^|[^\d])([+-]?\s*\d+(?:[.,]\d+)?)\s*(kg|kgs|kilograms?|lb|lbs|g|gr|grams?)?(?:$|[^a-z])`)

func (p *GenericParser) Parse(raw string) (*ScaleReading, error) {
	trimmed := strings.TrimSpace(raw)
	if trimmed == "" {
		return nil, fmt.Errorf("lectura vacía")
	}

	matches := genericRegex.FindStringSubmatch(trimmed)
	if len(matches) < 2 {
		return nil, fmt.Errorf("no se pudo identificar el peso")
	}
	value := strings.ReplaceAll(strings.TrimSpace(matches[1]), " ", "")
	separator := p.DecimalSeparator
	if separator == "" {
		separator = "."
	}
	if separator == "," {
		value = strings.ReplaceAll(value, ".", "")
		value = strings.ReplaceAll(value, ",", ".")
	} else {
		value = strings.ReplaceAll(value, ",", "")
	}
	weight, err := strconv.ParseFloat(value, 64)
	if err != nil {
		return nil, fmt.Errorf("valor de peso inválido")
	}

	unit := strings.ToLower(strings.TrimSpace(matches[2]))
	switch unit {
	case "kgs", "kilogram", "kilograms":
		unit = "kg"
	case "lbs":
		unit = "lb"
	case "gr", "gram", "grams":
		unit = "g"
	}
	if unit == "" {
		unit = strings.ToLower(strings.TrimSpace(p.DefaultUnit))
	}
	if unit == "" {
		unit = "kg"
	}

	upper := strings.ToUpper(trimmed)
	stable := !strings.Contains(upper, "US") && !strings.Contains(upper, "UNSTABLE")
	return &ScaleReading{Weight: weight, Unit: unit, IsStable: stable, Raw: trimmed, Timestamp: time.Now()}, nil
}

// TorreyParser handles Torrey scale strings like "01.345" or "P03.450".
type TorreyParser struct{}

var torreyRegex = regexp.MustCompile(`(?i)^P?(\d+[.,]\d{2,3})`)

func (p *TorreyParser) Parse(raw string) (*ScaleReading, error) {
	trimmed := strings.TrimSpace(raw)
	matches := torreyRegex.FindStringSubmatch(trimmed)
	if len(matches) < 2 {
		return nil, fmt.Errorf("no se pudo interpretar la lectura Torrey")
	}
	value := strings.ReplaceAll(matches[1], ",", ".")
	weight, err := strconv.ParseFloat(value, 64)
	if err != nil {
		return nil, fmt.Errorf("valor de peso Torrey inválido")
	}
	return &ScaleReading{Weight: weight, Unit: "kg", IsStable: true, Raw: trimmed, Timestamp: time.Now()}, nil
}
