package escpos

import (
	"bytes"
	"testing"
)

func TestBuildEscPos(t *testing.T) {
	tests := []struct {
		name       string
		text       string
		cutPaper   bool
		openDrawer bool
		wantPrefix []byte
		wantSuffix []byte
		contains   string
	}{
		{
			name:       "plain text only",
			text:       "Ticket #123\nTotal: $10.00\n",
			cutPaper:   false,
			openDrawer: false,
			wantPrefix: []byte("Ticket #123"),
			wantSuffix: []byte("$10.00\n"),
			contains:   "Ticket #123",
		},
		{
			name:       "with cash drawer kick",
			text:       "Ticket #124\n",
			cutPaper:   false,
			openDrawer: true,
			wantPrefix: []byte{0x1B, 0x70, 0x00, 0x19, 0xFA}, // ESC p 0 25 250
			contains:   "Ticket #124",
		},
		{
			name:       "with paper cut",
			text:       "Ticket #125\n",
			cutPaper:   true,
			openDrawer: false,
			wantSuffix: []byte{0x1D, 0x56, 0x00}, // GS V 0
			contains:   "Ticket #125",
		},
		{
			name:       "with drawer kick and paper cut",
			text:       "Ticket #126\n",
			cutPaper:   true,
			openDrawer: true,
			wantPrefix: []byte{0x1B, 0x70, 0x00, 0x19, 0xFA},
			wantSuffix: []byte{0x1D, 0x56, 0x00},
			contains:   "Ticket #126",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := BuildEscPos(tt.text, tt.cutPaper, tt.openDrawer)

			if len(tt.wantPrefix) > 0 {
				if !bytes.HasPrefix(got, tt.wantPrefix) {
					t.Errorf("BuildEscPos() prefix mismatch, got %v, want prefix %v", got[:len(tt.wantPrefix)], tt.wantPrefix)
				}
			}

			if len(tt.wantSuffix) > 0 {
				if !bytes.HasSuffix(got, tt.wantSuffix) {
					t.Errorf("BuildEscPos() suffix mismatch, got %v, want suffix %v", got[len(got)-len(tt.wantSuffix):], tt.wantSuffix)
				}
			}

			if !bytes.Contains(got, []byte(tt.contains)) {
				t.Errorf("BuildEscPos() missing text %q in output", tt.contains)
			}
		})
	}
}
