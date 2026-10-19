//go:build !windows

package printer

import (
	"context"
	"os"
	"time"
)

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
