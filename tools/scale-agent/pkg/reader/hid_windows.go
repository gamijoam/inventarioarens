//go:build windows

package reader

import (
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"strings"
	"sync"
	"unsafe"

	"golang.org/x/sys/windows"
)

type hidASCIIReader struct {
	handle       windows.Handle
	reportLength int
	pending      []byte
	closeOnce    sync.Once
	closeErr     error
}

var (
	hidDLL                   = windows.NewLazySystemDLL("hid.dll")
	hidGetPreparsedData      = hidDLL.NewProc("HidD_GetPreparsedData")
	hidFreePreparsedData     = hidDLL.NewProc("HidD_FreePreparsedData")
	hidParserGetCapabilities = hidDLL.NewProc("HidP_GetCaps")
)

func OpenHIDASCII(devicePath string) (io.ReadCloser, error) {
	devicePath = strings.TrimSpace(devicePath)
	if devicePath == "" {
		return nil, errors.New("el perfil HID requiere la ruta del dispositivo")
	}
	path, err := windows.UTF16PtrFromString(devicePath)
	if err != nil {
		return nil, fmt.Errorf("la ruta HID no es válida: %w", err)
	}

	handle, err := windows.CreateFile(
		path,
		windows.GENERIC_READ,
		windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE,
		nil,
		windows.OPEN_EXISTING,
		windows.FILE_ATTRIBUTE_NORMAL|windows.FILE_FLAG_OVERLAPPED,
		0,
	)
	if err != nil {
		return nil, fmt.Errorf("no se pudo abrir el dispositivo HID: %w", err)
	}

	var preparsed uintptr
	result, _, callErr := hidGetPreparsedData.Call(uintptr(handle), uintptr(unsafe.Pointer(&preparsed)))
	if result == 0 {
		_ = windows.CloseHandle(handle)
		return nil, fmt.Errorf("Windows no pudo leer el descriptor HID: %w", callErr)
	}
	defer hidFreePreparsedData.Call(preparsed)

	var capabilities [64]byte
	status, _, callErr := hidParserGetCapabilities.Call(preparsed, uintptr(unsafe.Pointer(&capabilities[0])))
	if status != 0x00110000 {
		_ = windows.CloseHandle(handle)
		return nil, fmt.Errorf("Windows no pudo consultar el reporte de entrada HID: status 0x%x (%v)", status, callErr)
	}

	reportLength := int(binary.LittleEndian.Uint16(capabilities[4:6]))
	if reportLength < 2 || reportLength > 64*1024-1 {
		_ = windows.CloseHandle(handle)
		return nil, fmt.Errorf("tamaño de reporte HID no compatible: %d", reportLength)
	}

	return &hidASCIIReader{handle: handle, reportLength: reportLength}, nil
}

func (r *hidASCIIReader) Read(output []byte) (int, error) {
	if len(output) == 0 {
		return 0, nil
	}
	if len(r.pending) > 0 {
		n := copy(output, r.pending)
		r.pending = r.pending[n:]
		return n, nil
	}
	event, err := windows.CreateEvent(nil, 1, 0, nil)
	if err != nil {
		return 0, err
	}
	defer windows.CloseHandle(event)

	report := make([]byte, r.reportLength)
	overlapped := windows.Overlapped{HEvent: event}
	var bytesRead uint32
	err = windows.ReadFile(r.handle, report, &bytesRead, &overlapped)
	if err != nil && !errors.Is(err, windows.ERROR_IO_PENDING) {
		return 0, err
	}
	if err == nil {
		// ReadFile completed synchronously and populated bytesRead.
	} else {
		if _, waitErr := windows.WaitForSingleObject(event, windows.INFINITE); waitErr != nil {
			return 0, waitErr
		}
		if resultErr := windows.GetOverlappedResult(r.handle, &overlapped, &bytesRead, false); resultErr != nil {
			return 0, resultErr
		}
	}

	// This adapter accepts HID devices that expose their weight as readable ASCII.
	// Binary reports require a protocol-specific parser and are intentionally ignored.
	if int(bytesRead) > len(report) {
		return 0, fmt.Errorf("Windows devolvió un reporte HID más largo que el tamaño anunciado")
	}
	frame := hidASCIIFrame(report[:bytesRead])
	written := copy(output, frame)
	r.pending = frame[written:]
	return written, nil
}

func (r *hidASCIIReader) Close() error {
	r.closeOnce.Do(func() {
		_ = windows.CancelIoEx(r.handle, nil)
		r.closeErr = windows.CloseHandle(r.handle)
	})
	return r.closeErr
}
