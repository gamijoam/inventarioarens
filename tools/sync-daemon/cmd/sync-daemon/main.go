package main

import (
	"context"
	"flag"
	"fmt"
	"log"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"

	"inventarioarens/sync-daemon/pkg/client"
	"inventarioarens/sync-daemon/pkg/config"
	"inventarioarens/sync-daemon/pkg/storage"
	"inventarioarens/sync-daemon/pkg/worker"
)

var (
	version = "0.1.0-go"
)

func defaultPaths() (string, string) {
	// Intentar ubicar rutas estándar en base al entorno
	cwd, _ := os.Getwd()
	defaultCfg := filepath.Join(cwd, "storage", "app", "sync-worker", "sync-config.json")
	defaultDB := filepath.Join(cwd, "storage", "framework", "local-dev.sqlite")

	// Si estamos en Windows en la ruta estándar del Motor
	programData := os.Getenv("ProgramData")
	if programData != "" {
		winCfg := filepath.Join(programData, "InventarioArens", "storage", "app", "sync-worker", "sync-config.json")
		winDB := filepath.Join(programData, "InventarioArens", "inventario.sqlite")
		if _, err := os.Stat(winCfg); err == nil {
			defaultCfg = winCfg
		}
		if _, err := os.Stat(winDB); err == nil {
			defaultDB = winDB
		}
	}

	return defaultCfg, defaultDB
}

func main() {
	defaultCfg, defaultDB := defaultPaths()

	cfgPath := flag.String("config", defaultCfg, "Ruta al archivo sync-config.json")
	dbPath := flag.String("database", defaultDB, "Ruta a la base de datos SQLite (inventario.sqlite)")
	intervalFlag := flag.Int("interval", 15, "Intervalo por defecto en segundos")
	onceFlag := flag.Bool("once", false, "Ejecuta un solo ciclo y termina")
	cyclesFlag := flag.Int("cycles", 0, "Cantidad máxima de ciclos (0 = infinito)")
	phpBinaryFlag := flag.String("php", "php", "Binario de PHP para ejecutar artisan sync:apply-inbox")
	backendRootFlag := flag.String("backend-root", "", "Directorio raíz del backend Laravel")
	versionFlag := flag.Bool("version", false, "Muestra la versión y sale")

	flag.Parse()

	if *versionFlag {
		fmt.Printf("InventarioArens Sync Daemon (Go) v%s\n", version)
		os.Exit(0)
	}

	log.Printf("[sync-daemon] Iniciando daemon de sincronización en Go v%s...", version)
	log.Printf("[sync-daemon] Config: %s", *cfgPath)
	log.Printf("[sync-daemon] SQLite: %s", *dbPath)

	db, err := storage.Open(*dbPath)
	if err != nil {
		log.Fatalf("[sync-daemon] Error abriendo base de datos SQLite: %v", err)
	}
	defer db.Close()

	httpClient := client.New(client.Options{Timeout: 30 * time.Second})

	// Applier local: invoca 'php artisan sync:apply-inbox'
	applier := worker.ApplyFunc(func(ctx context.Context, tenantSlug string, limit int) (int, error) {
		cmdArgs := []string{"artisan", "sync:apply-inbox", tenantSlug, fmt.Sprintf("--limit=%d", limit)}
		cmd := exec.CommandContext(ctx, *phpBinaryFlag, cmdArgs...)
		if *backendRootFlag != "" {
			cmd.Dir = *backendRootFlag
		}
		out, err := cmd.CombinedOutput()
		if err != nil {
			return 0, fmt.Errorf("artisan apply failed: %v, output: %s", err, string(out))
		}
		return 1, nil
	})

	w := worker.New(db, httpClient, applier)

	// Capturar señales para shutdown limpio
	ctx, cancel := context.WithCancel(context.Background())
	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, os.Interrupt, syscall.SIGTERM)

	go func() {
		<-sigChan
		log.Println("[sync-daemon] Señal de terminación recibida. Deteniendo...")
		cancel()
	}()

	scheduleMap := make(map[string]time.Time)
	cycle := 0
	hasFailures := false

	for {
		cycle++
		if ctx.Err() != nil {
			break
		}

		cfg, err := config.Load(*cfgPath)
		if err != nil {
			log.Printf("[sync-daemon] Advertencia leyendo configuración: %v", err)
		}

		if cfg != nil && cfg.Paused {
			log.Println("[sync-daemon] Sincronización pausada globalmente en sync-config.json.")
		} else if cfg != nil {
			activeTenants := cfg.ActiveTenants()
			now := time.Now()

			for slug, tCfg := range activeTenants {
				interval := time.Duration(tCfg.Interval) * time.Second
				if interval <= 0 {
					interval = time.Duration(*intervalFlag) * time.Second
				}

				lastRun, exists := scheduleMap[slug]
				if exists && now.Sub(lastRun) < interval {
					continue // Aún no le toca a este tenant
				}

				summary, err := w.RunTenantSync(ctx, slug, tCfg)
				scheduleMap[slug] = now

				if err != nil {
					hasFailures = true
					log.Printf("[sync-daemon] %s: ERROR - %v", slug, err)
				} else {
					log.Printf("[sync-daemon] %s", summary.String())
					if summary.Failed > 0 {
						hasFailures = true
					}
				}
			}
		}

		if *onceFlag {
			break
		}

		if *cyclesFlag > 0 && cycle >= *cyclesFlag {
			log.Printf("[sync-daemon] Límite de %d ciclos alcanzado. Saliendo.", *cyclesFlag)
			break
		}

		// Esperar 1 segundo antes de reevaluar tickers
		select {
		case <-ctx.Done():
			break
		case <-time.After(1 * time.Second):
		}
	}

	if hasFailures {
		os.Exit(1)
	}
	os.Exit(0)
}
