package reader

func hidASCIIFrame(report []byte) []byte {
	frame := make([]byte, 0, len(report)+1)
	nonText := 0
	for _, value := range report {
		if value == 0 || value == '\r' || value == '\n' || value == '\t' {
			continue
		}
		if value < 0x20 || value > 0x7e {
			nonText++
			continue
		}
		frame = append(frame, value)
	}
	if nonText > 1 || len(frame) == 0 {
		return []byte{'\n'}
	}
	return append(frame, '\n')
}
