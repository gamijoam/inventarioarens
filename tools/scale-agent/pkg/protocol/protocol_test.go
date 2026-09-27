package protocol

import (
	"testing"
)

func TestGenericParser(t *testing.T) {
	parser := &GenericParser{}

	tests := []struct {
		name       string
		raw        string
		wantWeight float64
		wantUnit   string
		wantStable bool
		wantErr    bool
	}{
		{
			name:       "Standard stable reading kg",
			raw:        "ST,GS,+  1.250kg\r\n",
			wantWeight: 1.250,
			wantUnit:   "kg",
			wantStable: true,
			wantErr:    false,
		},
		{
			name:       "Unstable reading kg",
			raw:        "US,GS,+  0.840kg\r\n",
			wantWeight: 0.840,
			wantUnit:   "kg",
			wantStable: false,
			wantErr:    false,
		},
		{
			name:       "Simple weight with unit",
			raw:        "  2.500 kg\r\n",
			wantWeight: 2.500,
			wantUnit:   "kg",
			wantStable: true,
			wantErr:    false,
		},
		{
			name:       "Simple weight in lbs",
			raw:        "ST,GS,+  5.200lb\r\n",
			wantWeight: 5.200,
			wantUnit:   "lb",
			wantStable: true,
			wantErr:    false,
		},
		{
			name:       "Zero weight",
			raw:        "ST,GS,+  0.000kg\r\n",
			wantWeight: 0.000,
			wantUnit:   "kg",
			wantStable: true,
			wantErr:    false,
		},
		{
			name:    "Invalid garbage data",
			raw:     "ERROR_NO_SCALE",
			wantErr: true,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			reading, err := parser.Parse(tt.raw)
			if (err != nil) != tt.wantErr {
				t.Fatalf("Parse() error = %v, wantErr %v", err, tt.wantErr)
			}
			if err == nil {
				if reading.Weight != tt.wantWeight {
					t.Errorf("Weight = %v, want %v", reading.Weight, tt.wantWeight)
				}
				if reading.Unit != tt.wantUnit {
					t.Errorf("Unit = %v, want %v", reading.Unit, tt.wantUnit)
				}
				if reading.IsStable != tt.wantStable {
					t.Errorf("IsStable = %v, want %v", reading.IsStable, tt.wantStable)
				}
			}
		})
	}
}

func TestTorreyParser(t *testing.T) {
	parser := &TorreyParser{}

	tests := []struct {
		name       string
		raw        string
		wantWeight float64
		wantUnit   string
		wantErr    bool
	}{
		{
			name:       "Torrey standard weight",
			raw:        "01.345\r",
			wantWeight: 1.345,
			wantUnit:   "kg",
			wantErr:    false,
		},
		{
			name:       "Torrey with P prefix",
			raw:        "P03.450\r",
			wantWeight: 3.450,
			wantUnit:   "kg",
			wantErr:    false,
		},
		{
			name:    "Torrey invalid",
			raw:     "INVALID",
			wantErr: true,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			reading, err := parser.Parse(tt.raw)
			if (err != nil) != tt.wantErr {
				t.Fatalf("Parse() error = %v, wantErr %v", err, tt.wantErr)
			}
			if err == nil {
				if reading.Weight != tt.wantWeight {
					t.Errorf("Weight = %v, want %v", reading.Weight, tt.wantWeight)
				}
				if reading.Unit != tt.wantUnit {
					t.Errorf("Unit = %v, want %v", reading.Unit, tt.wantUnit)
				}
			}
		})
	}
}
