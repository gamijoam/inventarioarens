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

	"balanzapro/catalog-search/pkg/index"
	"balanzapro/catalog-search/pkg/server"
)

func main() {
	port := flag.Int("port", 18888, "Puerto TCP donde escucha el buscador de catalogo")
	bind := flag.String("bind", "127.0.0.1", "IP donde escucha (default 127.0.0.1)")
	flag.Parse()

	idx := index.NewCatalogIndex()
	srv := server.NewServer(*port, idx)

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
		log.Printf("[CatalogSearch] Servidor de busqueda ultra-rapido activo en http://%s (Go nativo)", addr)
		if err := httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("[CatalogSearch] Error al iniciar servidor: %v", err)
		}
	}()

	<-stopChan
	log.Printf("[CatalogSearch] Deteniendo servidor de busqueda...")

	shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer shutdownCancel()

	if err := httpServer.Shutdown(shutdownCtx); err != nil {
		log.Printf("[CatalogSearch] Error durante shutdown: %v", err)
	}

	log.Printf("[CatalogSearch] Servidor detenido exitosamente.")
}
