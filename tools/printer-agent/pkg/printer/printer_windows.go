//go:build windows

package printer

import (
	"fmt"
	"syscall"
	"unsafe"
)

var (
	winspool             = syscall.NewLazyDLL("winspool.drv")
	procOpenPrinter      = winspool.NewProc("OpenPrinterW")
	procStartDocPrinter  = winspool.NewProc("StartDocPrinterW")
	procStartPagePrinter = winspool.NewProc("StartPagePrinter")
	procWritePrinter     = winspool.NewProc("WritePrinter")
	procEndPagePrinter   = winspool.NewProc("EndPagePrinter")
	procEndDocPrinter    = winspool.NewProc("EndDocPrinter")
	procClosePrinter     = winspool.NewProc("ClosePrinter")
)

type docInfoW struct {
	pDocName    *uint16
	pOutputFile *uint16
	pDatatype   *uint16
}

// printRawToSpooler sends raw bytes directly to the Windows print spooler
// without GDI rendering, preserving raw ESC/POS formatting and avoiding
// 2-column wrapping or extra margins.
func printRawToSpooler(printerName string, data []byte) error {
	if len(data) == 0 {
		return fmt.Errorf("no hay datos para imprimir")
	}

	pPrinterName, err := syscall.UTF16PtrFromString(printerName)
	if err != nil {
		return fmt.Errorf("nombre de impresora invalido: %w", err)
	}

	var hPrinter uintptr
	ret, _, err := procOpenPrinter.Call(
		uintptr(unsafe.Pointer(pPrinterName)),
		uintptr(unsafe.Pointer(&hPrinter)),
		0,
	)
	if ret == 0 {
		return fmt.Errorf("no se pudo abrir la impresora '%s': %v", printerName, err)
	}
	defer procClosePrinter.Call(hPrinter)

	docName, _ := syscall.UTF16PtrFromString("Inventario POS Ticket")
	datatype, _ := syscall.UTF16PtrFromString("RAW")
	di := docInfoW{
		pDocName:  docName,
		pDatatype: datatype,
	}

	ret, _, err = procStartDocPrinter.Call(hPrinter, 1, uintptr(unsafe.Pointer(&di)))
	if ret == 0 {
		return fmt.Errorf("StartDocPrinter fallo: %v", err)
	}
	defer procEndDocPrinter.Call(hPrinter)

	procStartPagePrinter.Call(hPrinter)
	defer procEndPagePrinter.Call(hPrinter)

	var written uint32
	ret, _, err = procWritePrinter.Call(
		hPrinter,
		uintptr(unsafe.Pointer(&data[0])),
		uintptr(len(data)),
		uintptr(unsafe.Pointer(&written)),
	)
	if ret == 0 || written == 0 {
		return fmt.Errorf("WritePrinter fallo: %v", err)
	}

	return nil
}
