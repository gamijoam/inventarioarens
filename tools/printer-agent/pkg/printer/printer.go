package printer

import (
	"context"
	"fmt"
	"net"
	"os"
	"os/exec"
	"runtime"
	"strings"
	"time"

	"inventarioarens/printer-agent/pkg/escpos"
	"inventarioarens/printer-agent/pkg/format"
)

type PrintOptions struct {
	PrinterType    string `json:"printer_type"`
	NetworkHost    string `json:"network_host"`
	NetworkPort    int    `json:"network_port"`
	CutPaper       bool   `json:"cut_paper"`
	OpenCashDrawer bool   `json:"open_cash_drawer"`
}

type PrintResult struct {
	Ok      bool   `json:"ok"`
	Status  string `json:"status,omitempty"`
	Message string `json:"message"`
	Printer string `json:"printer,omitempty"`
	Bytes   int    `json:"bytes,omitempty"`
}

type CommandExecutor interface {
	Execute(ctx context.Context, cmd string, args ...string) ([]byte, error)
}

type defaultExecutor struct{}

func (e *defaultExecutor) Execute(ctx context.Context, cmd string, args ...string) ([]byte, error) {
	c := exec.CommandContext(ctx, cmd, args...)
	return c.CombinedOutput()
}

type ThermalPrinter struct {
	executor CommandExecutor
}

func NewThermalPrinter() *ThermalPrinter {
	return &ThermalPrinter{
		executor: &defaultExecutor{},
	}
}

func NewThermalPrinterWithExecutor(exec CommandExecutor) *ThermalPrinter {
	return &ThermalPrinter{
		executor: exec,
	}
}

func (p *ThermalPrinter) Print(text string, printerName string, options PrintOptions) (*PrintResult, error) {
	if options.PrinterType == "network" {
		return p.printNetwork(text, options)
	}

	return p.printDriver(text, printerName, options)
}

func (p *ThermalPrinter) printNetwork(text string, options PrintOptions) (*PrintResult, error) {
	host := options.NetworkHost
	port := options.NetworkPort
	if port <= 0 {
		port = 9100
	}

	if host == "" {
		return &PrintResult{
			Ok:      false,
			Message: "Estacion de red sin network_host. Configura la IP de la impresora.",
		}, fmt.Errorf("missing network_host")
	}

	clean := format.Sanitize(text)
	data := escpos.BuildEscPos(clean, options.CutPaper, options.OpenCashDrawer)

	addr := fmt.Sprintf("%s:%d", host, port)
	conn, err := net.DialTimeout("tcp", addr, 5*time.Second)
	if err != nil {
		return &PrintResult{
			Ok:      false,
			Message: fmt.Sprintf("No se pudo conectar a %s (%v)", addr, err),
		}, err
	}
	defer conn.Close()

	_ = conn.SetDeadline(time.Now().Add(10 * time.Second))
	n, err := conn.Write(data)
	if err != nil {
		return &PrintResult{
			Ok:      false,
			Message: fmt.Sprintf("Envio incompleto a %s: %v", addr, err),
		}, err
	}

	// Small wait for buffer flush before socket closes
	time.Sleep(150 * time.Millisecond)

	return &PrintResult{
		Ok:      true,
		Status:  "printed",
		Message: fmt.Sprintf("Enviado a red %s", addr),
		Printer: addr,
		Bytes:   n,
	}, nil
}

func (p *ThermalPrinter) printDriver(text string, printerName string, options PrintOptions) (*PrintResult, error) {
	if printerName == "" {
		return &PrintResult{
			Ok:      false,
			Message: "Estacion sin printer_name. Configura la estacion con un nombre de impresora.",
		}, fmt.Errorf("missing printer_name")
	}

	clean := format.Sanitize(text)
	data := escpos.BuildEscPos(clean, options.CutPaper, options.OpenCashDrawer)

	// If using default executor, try direct RAW spooler printing first
	if _, isDefault := p.executor.(*defaultExecutor); isDefault {
		if err := printRawToSpooler(printerName, data); err == nil {
			return &PrintResult{
				Ok:      true,
				Status:  "printed",
				Message: fmt.Sprintf("Enviado a %s", printerName),
				Printer: printerName,
				Bytes:   len(data),
			}, nil
		}
	}

	// Fallback using executor (e.g. for mock test or when direct spooler call fails)
	tmpFile, err := os.CreateTemp("", "invtkt_*.bin")
	if err != nil {
		return &PrintResult{
			Ok:      false,
			Message: fmt.Sprintf("Fallo al crear archivo temporal: %v", err),
		}, err
	}
	tmpPath := tmpFile.Name()
	defer os.Remove(tmpPath)

	if _, err := tmpFile.Write(data); err != nil {
		tmpFile.Close()
		return &PrintResult{
			Ok:      false,
			Message: fmt.Sprintf("Fallo al escribir archivo temporal: %v", err),
		}, err
	}
	tmpFile.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()

	var cmd string
	var args []string

	if runtime.GOOS == "windows" {
		cmd = "powershell"
		psScript := fmt.Sprintf(`$code = @'
using System;
using System.IO;
using System.Runtime.InteropServices;
public class RawPrint {
  [StructLayout(LayoutKind.Sequential, CharSet=CharSet.Unicode)]
  public struct DOCINFOW {
    [MarshalAs(UnmanagedType.LPWStr)] public string pDocName;
    [MarshalAs(UnmanagedType.LPWStr)] public string pOutputFile;
    [MarshalAs(UnmanagedType.LPWStr)] public string pDataType;
  }
  [DllImport("winspool.Drv", EntryPoint="OpenPrinterW", SetLastError=true, CharSet=CharSet.Unicode)]
  public static extern bool OpenPrinter(string src, out IntPtr hPrinter, IntPtr pd);
  [DllImport("winspool.Drv", EntryPoint="ClosePrinter", SetLastError=true)]
  public static extern bool ClosePrinter(IntPtr hPrinter);
  [DllImport("winspool.Drv", EntryPoint="StartDocPrinterW", SetLastError=true, CharSet=CharSet.Unicode)]
  public static extern bool StartDocPrinter(IntPtr hPrinter, int level, ref DOCINFOW di);
  [DllImport("winspool.Drv", EntryPoint="EndDocPrinter", SetLastError=true)]
  public static extern bool EndDocPrinter(IntPtr hPrinter);
  [DllImport("winspool.Drv", EntryPoint="StartPagePrinter", SetLastError=true)]
  public static extern bool StartPagePrinter(IntPtr hPrinter);
  [DllImport("winspool.Drv", EntryPoint="EndPagePrinter", SetLastError=true)]
  public static extern bool EndPagePrinter(IntPtr hPrinter);
  [DllImport("winspool.Drv", EntryPoint="WritePrinter", SetLastError=true)]
  public static extern bool WritePrinter(IntPtr hPrinter, IntPtr pBytes, int dwCount, out int dwWritten);
  public static bool Send(string printer, string path) {
    byte[] b = File.ReadAllBytes(path);
    IntPtr h;
    DOCINFOW di = new DOCINFOW();
    di.pDocName = "Ticket POS";
    di.pDataType = "RAW";
    if (!OpenPrinter(printer, out h, IntPtr.Zero)) return false;
    bool ok = StartDocPrinter(h, 1, ref di);
    if (ok) ok = StartPagePrinter(h);
    IntPtr p = Marshal.AllocCoTaskMem(b.Length);
    Marshal.Copy(b, 0, p, b.Length);
    int written;
    if (ok) ok = WritePrinter(h, p, b.Length, out written);
    Marshal.FreeCoTaskMem(p);
    if (ok) { EndPagePrinter(h); EndDocPrinter(h); }
    ClosePrinter(h);
    return ok;
  }
}
'@
Add-Type -TypeDefinition $code -Language CSharp | Out-Null
if (-not [RawPrint]::Send('%s', '%s')) {
  Get-Content -LiteralPath '%s' -Raw | Out-Printer -Name '%s'
}
`, escapePowerShellLiteral(printerName), escapePowerShellLiteral(tmpPath), escapePowerShellLiteral(tmpPath), escapePowerShellLiteral(printerName))
		args = []string{"-NoProfile", "-NonInteractive", "-Command", psScript}
	} else {
		cmd = "lp"
		args = []string{"-d", printerName, "-o", "raw", tmpPath}
	}

	out, err := p.executor.Execute(ctx, cmd, args...)
	if err != nil {
		return &PrintResult{
			Ok:      false,
			Message: fmt.Sprintf("Fallo al imprimir en %s: %v (%s)", printerName, err, string(out)),
		}, err
	}

	return &PrintResult{
		Ok:      true,
		Status:  "printed",
		Message: fmt.Sprintf("Enviado a %s", printerName),
		Printer: printerName,
		Bytes:   len(data),
	}, nil
}

// escapePowerShellLiteral keeps filesystem paths and printer names inside a literal string.
func escapePowerShellLiteral(value string) string {
	return strings.ReplaceAll(value, "'", "''")
}
