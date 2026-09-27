package escpos

import "bytes"

// Command constants for ESC/POS protocol.
var (
	// OpenDrawerCmd: ESC p 0 25 250 (pin 2, standard EPSON kick).
	OpenDrawerCmd = []byte{0x1B, 0x70, 0x00, 0x19, 0xFA}

	// CutPaperCmd: GS V 0 (full cut).
	CutPaperCmd = []byte{0x1D, 0x56, 0x00}
)

// BuildEscPos constructs a raw ESC/POS byte slice with optional drawer kick and paper cut.
func BuildEscPos(text string, cutPaper bool, openDrawer bool) []byte {
	var buf bytes.Buffer

	if openDrawer {
		buf.Write(OpenDrawerCmd)
	}

	buf.WriteString(text)

	if cutPaper {
		buf.Write(CutPaperCmd)
	}

	return buf.Bytes()
}
