//go:build !windows

package reader

import (
	"errors"
	"io"
)

func OpenHIDASCII(string) (io.ReadCloser, error) {
	return nil, errors.New("la conexión HID directa solo está disponible en Windows")
}
