//go:build windows

package main

import (
	"fmt"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"time"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"

	"inventarioarens/printer-agent/pkg/printer"
	"inventarioarens/printer-agent/pkg/server"
)

const (
	serviceName = "BalanzaProPrinter"
	serviceDesc = "Agente local de impresion termica y digital para BalanzaPro / InventarioArens"
)

type printerService struct {
	port int
	bind string
}

func (m *printerService) Execute(args []string, r <-chan svc.ChangeRequest, changes chan<- svc.Status) (ssec bool, errno uint32) {
	const cmdsAccepted = svc.AcceptStop | svc.AcceptShutdown
	changes <- svc.Status{State: svc.StartPending}

	thermalPrinter := printer.NewThermalPrinter()
	srv := server.NewServer(m.port, thermalPrinter)
	addr := fmt.Sprintf("%s:%d", m.bind, m.port)
	httpServer := &http.Server{
		Addr:              addr,
		Handler:           srv,
		ReadHeaderTimeout: 5 * time.Second,
		IdleTimeout:       60 * time.Second,
	}

	go func() {
		_ = httpServer.ListenAndServe()
	}()

	changes <- svc.Status{State: svc.Running, Accepts: cmdsAccepted}

	for req := range r {
		switch req.Cmd {
		case svc.Interrogate:
			changes <- req.CurrentStatus
		case svc.Stop, svc.Shutdown:
			changes <- svc.Status{State: svc.StopPending}
			_ = httpServer.Close()
			return false, 0
		default:
			log.Printf("Comando de servicio inesperado #%d", req.Cmd)
		}
	}
	return false, 0
}

func runService(port int, bind string) error {
	return svc.Run(serviceName, &printerService{port: port, bind: bind})
}

func isWindowsService() bool {
	isSvc, err := svc.IsWindowsService()
	if err != nil {
		return false
	}
	return isSvc
}

func installService(port int, bind string) error {
	exePath, err := os.Executable()
	if err != nil {
		return err
	}
	exePath, err = filepath.Abs(exePath)
	if err != nil {
		return err
	}

	m, err := mgr.Connect()
	if err != nil {
		return fmt.Errorf("error al conectar con SCM: %w", err)
	}
	defer m.Disconnect()

	s, err := m.OpenService(serviceName)
	if err == nil {
		s.Close()
		return fmt.Errorf("el servicio %s ya existe", serviceName)
	}

	cfg := mgr.Config{
		ServiceType:  windows.SERVICE_WIN32_OWN_PROCESS,
		StartType:    mgr.StartAutomatic,
		ErrorControl: mgr.ErrorNormal,
		DisplayName:  "BalanzaPro - Agente de Impresion",
		Description:  serviceDesc,
	}

	s, err = m.CreateService(serviceName, exePath, cfg, fmt.Sprintf("-port=%d", port), fmt.Sprintf("-bind=%s", bind))
	if err != nil {
		return fmt.Errorf("error al crear servicio: %w", err)
	}
	defer s.Close()

	if err := s.Start(); err != nil {
		log.Printf("Servicio instalado. Iniciando: %v", err)
	} else {
		log.Printf("Servicio %s instalado e iniciado correctamente.", serviceName)
	}
	return nil
}

func uninstallService() error {
	m, err := mgr.Connect()
	if err != nil {
		return fmt.Errorf("error al conectar con SCM: %w", err)
	}
	defer m.Disconnect()

	s, err := m.OpenService(serviceName)
	if err != nil {
		return fmt.Errorf("el servicio %s no existe", serviceName)
	}
	defer s.Close()

	_, _ = s.Control(svc.Stop)
	time.Sleep(1 * time.Second)

	if err := s.Delete(); err != nil {
		return fmt.Errorf("error al eliminar servicio: %w", err)
	}
	log.Printf("Servicio %s desinstalado correctamente.", serviceName)
	return nil
}
