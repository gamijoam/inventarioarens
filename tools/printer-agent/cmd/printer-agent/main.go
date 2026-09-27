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

	"inventarioarens/printer-agent/pkg/printer"
	"inventarioarens/printer-agent/pkg/server"
)

func main() {
	port := flag.Int("port", 17777, "Puerto TCP donde escucha el agente de impresion")
	bind := flag.String("bind", "127.0.0.1", "IP donde escucha (default 127.0.0.1)")
	flag.Parse()

	if *port < 1024 || *port > 65535 {
		log.Fatalf("Puerto invalido: %d (usa 1024-65535)", *port)
	}

	thermalPrinter := printer.NewThermalPrinter()
	srv := server.NewServer(*port, thermalPrinter)

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
		log.Printf("[PrinterAgent] Iniciando agente de impresion en http://%s (Go nativo)", addr)
		if err := httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("[PrinterAgent] Error al iniciar servidor: %v", err)
		}
	}()

	<-stopChan
	log.Printf("[PrinterAgent] Deteniendo agente de impresion...")

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	if err := httpServer.Shutdown(ctx); err != nil {
		log.Printf("[PrinterAgent] Error durante shutdown: %v", err)
	}

	log.Printf("[PrinterAgent] Servidor detenido exitosamente.")
}
