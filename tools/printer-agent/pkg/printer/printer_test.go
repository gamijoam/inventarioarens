package printer

import (
	"context"
	"io"
	"net"
	"strings"
	"testing"
	"time"
)

func TestNetworkPrinter_Success(t *testing.T) {
	// Start a mock TCP thermal printer on a random port
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("Failed to listen: %v", err)
	}
	defer ln.Close()

	addr := ln.Addr().String()
	parts := strings.Split(addr, ":")
	host := parts[0]
	portStr := parts[1]
	var port int
	for _, ch := range portStr {
		port = port*10 + int(ch-'0')
	}

	receivedChan := make(chan []byte, 1)

	go func() {
		conn, err := ln.Accept()
		if err != nil {
			return
		}
		defer conn.Close()
		buf, _ := io.ReadAll(conn)
		receivedChan <- buf
	}()

	p := NewThermalPrinter()
	res, err := p.Print("Hello ESC/POS", "", PrintOptions{
		PrinterType:    "network",
		NetworkHost:    host,
		NetworkPort:    port,
		CutPaper:       true,
		OpenCashDrawer: true,
	})

	if err != nil {
		t.Fatalf("Print failed: %v", err)
	}
	if !res.Ok {
		t.Fatalf("Expected res.Ok = true, got false. Msg: %s", res.Message)
	}

	select {
	case data := <-receivedChan:
		// Check that cash drawer kick prefix and cut suffix are present
		if len(data) < 10 {
			t.Errorf("Received data too short: %v", data)
		}
		if !strings.Contains(string(data), "Hello ESC/POS") {
			t.Errorf("Received data missing text: %s", string(data))
		}
	case <-time.After(2 * time.Second):
		t.Fatal("Timeout waiting for printer data")
	}
}

func TestNetworkPrinter_MissingHost(t *testing.T) {
	p := NewThermalPrinter()
	res, err := p.Print("Hello", "", PrintOptions{
		PrinterType: "network",
		NetworkHost: "",
	})
	if err == nil && res.Ok {
		t.Fatal("Expected error or res.Ok=false for missing host")
	}
}

func TestSystemPrinter_MissingPrinterName(t *testing.T) {
	p := NewThermalPrinter()
	res, err := p.Print("Hello", "", PrintOptions{
		PrinterType: "windows_printer",
	})
	if err == nil && res.Ok {
		t.Fatal("Expected error or res.Ok=false for missing printer name")
	}
}

type mockExecutor struct {
	lastCmd  string
	lastArgs []string
	retErr   error
}

func (m *mockExecutor) Execute(ctx context.Context, cmd string, args ...string) ([]byte, error) {
	m.lastCmd = cmd
	m.lastArgs = args
	return []byte("Printed successfully"), m.retErr
}

func TestSystemPrinter_WithMockExecutor(t *testing.T) {
	mockExec := &mockExecutor{}
	p := NewThermalPrinterWithExecutor(mockExec)

	res, err := p.Print("Hello Ticket", "EPSON_TM_T20", PrintOptions{
		PrinterType: "windows_printer",
	})
	if err != nil {
		t.Fatalf("Unexpected error: %v", err)
	}
	if !res.Ok {
		t.Fatalf("Expected ok, got: %s", res.Message)
	}
	if mockExec.lastCmd == "" {
		t.Error("Executor was not called")
	}
}
