package reader

import (
	"bytes"
	"testing"
)

func TestHIDASCIIFrameKeepsWeightWithCRLFAndReportID(t *testing.T) {
	got := hidASCIIFrame([]byte{1, 'S', 'T', ',', 'G', 'S', ' ', '1', '.', '2', '5', '0', 'k', 'g', '\r', '\n'})
	want := []byte("ST,GS 1.250kg\n")
	if !bytes.Equal(got, want) {
		t.Fatalf("hidASCIIFrame() = %q, want %q", got, want)
	}
}

func TestHIDASCIIFrameDropsBinaryReportsWithoutInventingData(t *testing.T) {
	got := hidASCIIFrame([]byte{0x01, 0x02, 0x80, 0x00})
	if !bytes.Equal(got, []byte{'\n'}) {
		t.Fatalf("hidASCIIFrame() = %v, want an empty frame", got)
	}
}

func TestHIDASCIIFrameDropsEmptyReports(t *testing.T) {
	got := hidASCIIFrame([]byte{0, 0, '\r', '\n'})
	if !bytes.Equal(got, []byte{'\n'}) {
		t.Fatalf("hidASCIIFrame() = %v, want an empty frame", got)
	}
}
