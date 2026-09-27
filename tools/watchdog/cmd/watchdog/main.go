package main

import (
	"flag"
	"log"
	"os"
	"os/signal"
	"syscall"
	"time"

	"inventarioarens/watchdog/pkg/monitor"
)

func main() {
	interval := flag.Int("interval", 15, "Intervalo de sondeo en segundos")
	autoHeal := flag.Bool("auto-heal", true, "Habilitar auto-recuperacion ejecutando comando de reinicio")
	threshold := flag.Int("threshold", 3, "Fallos consecutivos necesarios para activar reinicio")
	flag.Parse()

	defaultTargets := []monitor.Target{
		{
			Name:           "catalog-search",
			URL:            "http://127.0.0.1:18888/health",
			RestartCommand: "systemctl restart balanzapro-catalog-search",
			TimeoutSec:     2,
		},
		{
			Name:           "ws-hub",
			URL:            "http://127.0.0.1:16666/health",
			RestartCommand: "systemctl restart balanzapro-ws-hub",
			TimeoutSec:     2,
		},
	}

	m := monitor.NewMonitor()
	log.Printf("[Watchdog] Guardian de microservicios activo (sondeo cada %ds, auto-heal: %v)", *interval, *autoHeal)

	ticker := time.NewTicker(time.Duration(*interval) * time.Second)
	defer ticker.Stop()

	stopChan := make(chan os.Signal, 1)
	signal.Notify(stopChan, os.Interrupt, syscall.SIGTERM)

	// Run initial check immediately
	checkAll(m, defaultTargets, *autoHeal, *threshold)

	for {
		select {
		case <-ticker.C:
			checkAll(m, defaultTargets, *autoHeal, *threshold)
		case <-stopChan:
			log.Println("[Watchdog] Deteniendo guardian...")
			return
		}
	}
}

func checkAll(m *monitor.Monitor, targets []monitor.Target, autoHeal bool, threshold int) {
	for _, t := range targets {
		st := m.CheckTarget(t)
		if st.Healthy {
			// Quiet healthy log
		} else {
			log.Printf("[Watchdog] ALERTA: %s DOWN (%s, fallos: %d)", st.TargetName, st.Error, st.ConsecutiveFailures)
			if autoHeal && st.ConsecutiveFailures >= threshold {
				log.Printf("[Watchdog] AUTO-HEALING: Ejecutando reinicio de %s...", t.Name)
				restarted, err := m.AutoHeal(t, threshold)
				if err != nil {
					log.Printf("[Watchdog] ERROR reiniciando %s: %v", t.Name, err)
				} else if restarted {
					log.Printf("[Watchdog] %s reiniciado exitosamente.", t.Name)
				}
			}
		}
	}
}
