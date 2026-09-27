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

	"inventarioarens/ws-hub/pkg/hub"
	"inventarioarens/ws-hub/pkg/server"
)

func main() {
	defaultPort := 16666
	port := flag.Int("port", defaultPort, "Puerto TCP donde escucha el WebSocket Hub")
	bind := flag.String("bind", "127.0.0.1", "IP donde escucha el WebSocket Hub")
	authKey := flag.String("auth-key", os.Getenv("WS_HUB_AUTH_KEY"), "Clave secreta opcional para autenticar llamadas a /publish")
	flag.Parse()

	h := hub.NewHub()
	srv := server.NewServer(*port, h, *authKey)

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
		log.Printf("[WsHub] Servidor WebSocket Hub activo en ws://%s/ws y http://%s/publish", addr, addr)
		if err := httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("[WsHub] Error al iniciar servidor: %v", err)
		}
	}()

	<-stopChan
	log.Printf("[WsHub] Deteniendo servidor WebSocket Hub...")

	shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer shutdownCancel()

	if err := httpServer.Shutdown(shutdownCtx); err != nil {
		log.Printf("[WsHub] Error durante shutdown: %v", err)
	}

	log.Printf("[WsHub] Servidor WebSocket Hub detenido exitosamente.")
}
