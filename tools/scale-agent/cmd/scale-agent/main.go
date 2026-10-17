package main

import (
	"context"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"

	"go.bug.st/serial"

	"balanzapro/scale-agent/pkg/protocol"
	"balanzapro/scale-agent/pkg/reader"
	"balanzapro/scale-agent/pkg/server"
)

func main() {
	port := flag.Int("port", 19999, "Puerto TCP donde escucha el agente de balanza")
	bind := flag.String("bind", "127.0.0.1", "IP donde escucha (por defecto 127.0.0.1)")
	transport := flag.String("transport", "disabled", "Transporte: disabled, serial/com o tcp/network")
	device := flag.String("device", "", "Puerto COM, dirección TCP o ruta del dispositivo HID")
	protoFlag := flag.String("protocol", "generic", "Protocolo de balanza: generic o torrey")
	baud := flag.Int("baud", 9600, "Velocidad serie")
	dataBits := flag.Int("data-bits", 8, "Bits de datos")
	parity := flag.String("parity", "none", "Paridad: none, odd, even")
	stopBits := flag.Float64("stop-bits", 1, "Bits de parada: 1, 1.5 o 2")
	tcpAddress := flag.String("tcp-address", "", "Dirección IP:puerto de balanza de red")
	decimal := flag.String("decimal-separator", ".", "Separador decimal del perfil")
	defaultUnit := flag.String("unit", "kg", "Unidad del perfil")
	mock := flag.Bool("mock", false, "Activa el simulador solo para pruebas")
	mockWeight := flag.Float64("mock-weight", 1.250, "Peso inicial del simulador (solo pruebas)")
	flag.Parse()

	parser, err := newParser(*protoFlag, *decimal, *defaultUnit)
	if err != nil {
		log.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	var scaleReader reader.ScaleReader
	if *mock {
		log.Printf("[ScaleAgent] Modo de prueba simulado (%.3f %s)", *mockWeight, *defaultUnit)
		mockReader := reader.NewMockReader(*mockWeight, *defaultUnit, 200*time.Millisecond)
		go mockReader.Start(ctx)
		scaleReader = mockReader
	} else {
		open, err := newOpener(*transport, *device, *tcpAddress, *baud, *dataBits, *parity, *stopBits)
		if err != nil {
			log.Fatal(err)
		}
		if open == nil {
			log.Printf("[ScaleAgent] Sin perfil de balanza configurado; se muestra desconectada.")
			scaleReader = reader.NewDisconnectedReader()
		} else {
			scaleReader = reader.NewReconnectingReader(open, parser)
			go scaleReader.Start(ctx)
			log.Printf("[ScaleAgent] Perfil %s activo; transporte %s.", *protoFlag, *transport)
		}
	}

	srv := server.NewServer(*port, scaleReader)
	addr := fmt.Sprintf("%s:%d", *bind, *port)
	httpServer := &http.Server{Addr: addr, Handler: srv, ReadHeaderTimeout: 5 * time.Second, IdleTimeout: 60 * time.Second}

	stopChan := make(chan os.Signal, 1)
	signal.Notify(stopChan, os.Interrupt, syscall.SIGTERM)
	go func() {
		log.Printf("[ScaleAgent] Servicio activo en http://%s", addr)
		if err := httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Printf("[ScaleAgent] Error al iniciar servidor: %v", err)
		}
	}()
	<-stopChan
	cancel()
	shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer shutdownCancel()
	if err := httpServer.Shutdown(shutdownCtx); err != nil {
		log.Printf("[ScaleAgent] Error al cerrar el servidor: %v", err)
	}
}

func newParser(name, decimal, unit string) (protocol.Parser, error) {
	switch strings.ToLower(name) {
	case "generic", "default":
		return &protocol.GenericParser{DecimalSeparator: decimal, DefaultUnit: unit}, nil
	case "torrey":
		return &protocol.TorreyParser{}, nil
	default:
		return nil, fmt.Errorf("protocolo no reconocido: %s", name)
	}
}

func newOpener(transport, device, tcpAddress string, baud, dataBits int, parity string, stopBits float64) (func() (io.ReadCloser, error), error) {
	switch strings.ToLower(transport) {
	case "", "disabled", "none":
		return nil, nil
	case "serial", "com", "usb-serial":
		if strings.TrimSpace(device) == "" {
			return nil, fmt.Errorf("el perfil serie requiere un puerto COM, por ejemplo COM3")
		}
		mode := &serial.Mode{BaudRate: baud, DataBits: dataBits, Parity: serial.NoParity, StopBits: serial.OneStopBit}
		switch strings.ToLower(parity) {
		case "none", "":
			mode.Parity = serial.NoParity
		case "odd":
			mode.Parity = serial.OddParity
		case "even":
			mode.Parity = serial.EvenParity
		default:
			return nil, fmt.Errorf("paridad serie no compatible: %s", parity)
		}
		switch stopBits {
		case 1:
			mode.StopBits = serial.OneStopBit
		case 1.5:
			mode.StopBits = serial.OnePointFiveStopBits
		case 2:
			mode.StopBits = serial.TwoStopBits
		default:
			return nil, fmt.Errorf("bits de parada inválidos: %.1f", stopBits)
		}
		return func() (io.ReadCloser, error) { return serial.Open(device, mode) }, nil
	case "tcp", "network":
		if strings.TrimSpace(tcpAddress) == "" {
			return nil, fmt.Errorf("el perfil de red requiere una dirección IP:puerto")
		}
		return func() (io.ReadCloser, error) {
			dialer := net.Dialer{Timeout: 5 * time.Second}
			return dialer.Dial("tcp", tcpAddress)
		}, nil
	case "hid", "usb-hid", "hid-ascii":
		if strings.TrimSpace(device) == "" {
			return nil, fmt.Errorf("el perfil HID requiere la ruta del dispositivo de Windows")
		}
		return func() (io.ReadCloser, error) { return reader.OpenHIDASCII(device) }, nil
	default:
		return nil, fmt.Errorf("transporte de balanza no reconocido: %s", transport)
	}
}
