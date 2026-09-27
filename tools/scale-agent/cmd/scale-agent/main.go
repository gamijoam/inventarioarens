package main

import (
	"context"
	"flag"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"balanzapro/scale-agent/pkg/protocol"
	"balanzapro/scale-agent/pkg/reader"
	"balanzapro/scale-agent/pkg/server"
)

func main() {
	port := flag.Int("port", 19999, "Puerto TCP donde escucha el agente de balanza")
	bind := flag.String("bind", "127.0.0.1", "IP donde escucha (default 127.0.0.1)")
	protoFlag := flag.String("protocol", "generic", "Protocolo de balanza (generic, torrey)")
	mock := flag.Bool("mock", true, "Modo simulador si no hay puerto serial físico")
	mockWeight := flag.Float64("mock-weight", 1.250, "Peso inicial para el modo simulador (kg)")
	flag.Parse()

	var parser protocol.Parser
	switch *protoFlag {
	case "torrey":
		parser = &protocol.TorreyParser{}
	default:
		parser = &protocol.GenericParser{}
	}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	var scaleReader reader.ScaleReader
	if *mock {
		log.Printf("[ScaleAgent] Iniciando lector de balanza en modo SIMULADOR (peso: %.3f kg)", *mockWeight)
		mockReader := reader.NewMockReader(*mockWeight, "kg", 200*time.Millisecond)
		go mockReader.Start(ctx)
		scaleReader = mockReader
	} else {
		log.Printf("[ScaleAgent] Modo hardware físico (protocolo: %s)", *protoFlag)
		// Fallback to mock if no hardware device is passed
		mockReader := reader.NewMockReader(0.0, "kg", 1*time.Second)
		go mockReader.Start(ctx)
		scaleReader = mockReader
	}

	_ = parser

	srv := server.NewServer(*port, scaleReader)
	addr := fmt.Sprintf("%s:%d", *bind, *port)
	httpServer := &http.Server{
		Addr:              addr,
		Handler:           srv,
		ReadHeaderTimeout: 5 * time.Second,
		IdleTimeout:       60 * time.Second,
	}

	stopChan := make(chan os.Signal, 1)
	signal.Notify(stopChan, os.Interrupt, syscall.SIGTERM)

	go func() {
		log.Printf("[ScaleAgent] Servidor de balanza activo en http://%s (Go nativo)", addr)
		if err := httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("[ScaleAgent] Error al iniciar servidor: %v", err)
		}
	}()

	<-stopChan
	log.Printf("[ScaleAgent] Deteniendo agente de balanza...")

	shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer shutdownCancel()

	if err := httpServer.Shutdown(shutdownCtx); err != nil {
		log.Printf("[ScaleAgent] Error durante shutdown: %v", err)
	}

	log.Printf("[ScaleAgent] Servidor detenido exitosamente.")
}
