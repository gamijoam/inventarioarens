//go:build !windows

package printer

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"strings"
	"time"
)

func getDefaultPrinterName() (string, error) {
	output, err := exec.Command("lpstat", "-d").CombinedOutput()
	if err != nil {
		return "", fmt.Errorf("no se pudo consultar la impresora predeterminada: %w (%s)", err, strings.TrimSpace(string(output)))
	}
	const prefix = "system default destination:"
	line := strings.TrimSpace(string(output))
	if !strings.HasPrefix(line, prefix) {
		return "", fmt.Errorf("CUPS no tiene una impresora predeterminada configurada")
	}
	name := strings.TrimSpace(strings.TrimPrefix(line, prefix))
	if name == "" {
		return "", fmt.Errorf("CUPS no tiene una impresora predeterminada configurada")
	}
	return name, nil
}

// printRawToSpooler on non-Windows systems writes raw data to a temp file and invokes lp -o raw.
func printRawToSpooler(printerName string, data []byte) error {
	tmpFile, err := os.CreateTemp("", "invtkt_*.raw")
	if err != nil {
		return err
	}
	tmpPath := tmpFile.Name()
	defer os.Remove(tmpPath)

	if _, err := tmpFile.Write(data); err != nil {
		tmpFile.Close()
		return err
	}
	tmpFile.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()

	exec := &defaultExecutor{}
	_, err = exec.Execute(ctx, "lp", "-d", printerName, "-o", "raw", tmpPath)
	return err
}
