package main

import (
	"archive/zip"
	"embed"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"io/fs"
	"log"
	"mime"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"syscall"
	"time"

	"balanzapro/motor-local/pkg/config"
)

//go:embed all:web/*
var webFS embed.FS

type Supervisor struct {
	cfg          *config.Config
	cmd          *exec.Cmd
	printerCmd   *exec.Cmd
	scaleCmd     *exec.Cmd
	mu           sync.Mutex
	running      bool
	stopChan     chan struct{}
	reverseProxy *httputil.ReverseProxy
}

func mergeEnvironment(base []string, overrides ...string) []string {
	values := make(map[string]string, len(base)+len(overrides))
	order := make([]string, 0, len(base)+len(overrides))
	for _, entry := range append(append([]string(nil), base...), overrides...) {
		key, _, ok := strings.Cut(entry, "=")
		if !ok || key == "" {
			continue
		}
		canonical := strings.ToUpper(key)
		if _, exists := values[canonical]; !exists {
			order = append(order, canonical)
		}
		values[canonical] = entry
	}
	result := make([]string, 0, len(order))
	for _, key := range order {
		result = append(result, values[key])
	}
	return result
}

func getLANIPs() []string {
	var ips []string
	addrs, err := net.InterfaceAddrs()
	if err != nil {
		return []string{"127.0.0.1"}
	}
	for _, address := range addrs {
		if ipnet, ok := address.(*net.IPNet); ok && !ipnet.IP.IsLoopback() {
			if ipnet.IP.To4() != nil {
				ips = append(ips, ipnet.IP.String())
			}
		}
	}
	if len(ips) == 0 {
		return []string{"127.0.0.1"}
	}
	return ips
}

func main() {
	proxyPort := flag.Int("port", 8787, "Puerto del servidor proxy LAN local")
	backendPort := flag.Int("backend-port", 8788, "Puerto interno para el backend de Laravel")
	dataDir := flag.String("data", "", "Directorio de datos (SQLite, tokens, etc.)")
	phpBin := flag.String("php", "php", "Ruta al ejecutable de PHP")
	backendRoot := flag.String("backend", ".", "Ruta a la carpeta raíz de Laravel (donde está artisan)")
	cloudURL := flag.String("cloud", "https://app.balanzapro.com", "URL de la plataforma en la nube")
	tenantSlug := flag.String("tenant", "", "Slug de la empresa (si está vacío, se lee de config.json)")
	offlineMode := flag.Bool("offline", true, "Iniciar en modo 100% offline (sin sincronización)")
	flag.Parse()

	// Ensure MIME types
	_ = mime.AddExtensionType(".js", "application/javascript")
	_ = mime.AddExtensionType(".mjs", "application/javascript")
	_ = mime.AddExtensionType(".css", "text/css")
	_ = mime.AddExtensionType(".svg", "image/svg+xml")
	_ = mime.AddExtensionType(".json", "application/json")

	// Locate executable base directory
	exePath, _ := os.Executable()
	baseDir := filepath.Dir(exePath)

	// Write logs to the writable data directory instead of Program Files.
	logDataRoot := *dataDir
	if logDataRoot == "" {
		logDataRoot = os.Getenv("INVENTARIO_DATA_ROOT")
	}
	if logDataRoot == "" && runtime.GOOS == "windows" {
		programData := os.Getenv("ProgramData")
		if programData == "" {
			programData = `C:\ProgramData`
		}
		logDataRoot = filepath.Join(programData, "BalanzaPro")
	}
	logDir := filepath.Join(logDataRoot, "storage", "logs")
	if logDataRoot == "" {
		logDir = filepath.Join(baseDir, "logs")
	}
	if err := os.MkdirAll(logDir, 0755); err != nil {
		logDir = filepath.Join(os.TempDir(), "BalanzaPro", "logs")
		_ = os.MkdirAll(logDir, 0755)
	}
	logFile, err := os.OpenFile(filepath.Join(logDir, "motor-local.log"), os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0666)
	if err == nil {
		log.SetOutput(logFile)
		defer logFile.Close()
	} else {
		log.SetOutput(io.Discard)
	}

	log.Printf("[MotorLocal] Iniciando servidor desde: %s", baseDir)

	// Auto-detect portable PHP in baseDir/php/php.exe if not overridden
	actualPHPBin := *phpBin
	if actualPHPBin == "php" {
		localPHP := filepath.Join(baseDir, "php", "php.exe")
		if _, err := os.Stat(localPHP); err == nil {
			actualPHPBin = localPHP
			log.Printf("[MotorLocal] PHP portable detectado en: %s", actualPHPBin)
		}
	}

	// Auto-detect backend in baseDir/backend if not overridden
	actualBackendRoot := *backendRoot
	if actualBackendRoot == "." {
		localBackend := filepath.Join(baseDir, "backend")
		if _, err := os.Stat(localBackend); err == nil {
			actualBackendRoot = localBackend
			log.Printf("[MotorLocal] Backend Laravel detectado en: %s", actualBackendRoot)
		}
	}

	// Default data directory
	actualDataDir := *dataDir
	if actualDataDir == "" {
		if configured := os.Getenv("INVENTARIO_DATA_ROOT"); configured != "" {
			actualDataDir = configured
		} else if runtime.GOOS == "windows" {
			programData := os.Getenv("ProgramData")
			if programData == "" {
				programData = `C:\ProgramData`
			}
			actualDataDir = filepath.Join(programData, "BalanzaPro")
		} else {
			actualDataDir = filepath.Join(actualBackendRoot, "storage", "framework")
		}
	}
	_ = os.MkdirAll(actualDataDir, 0755)

	lanIPs := getLANIPs()
	mainLANIP := lanIPs[0]

	cfg, err := config.LoadConfig(actualDataDir, baseDir)
	if err != nil {
		log.Printf("[MotorLocal] Advertencia leyendo config.json: %v", err)
	}

	if *proxyPort != 8787 || cfg.ProxyPort == 0 {
		cfg.ProxyPort = *proxyPort
	}
	if *backendPort != 8788 || cfg.BackendPort == 0 {
		cfg.BackendPort = *backendPort
	}
	if *tenantSlug != "" {
		cfg.TenantSlug = *tenantSlug
	}
	if *cloudURL != "https://app.balanzapro.com" || cfg.CloudURL == "" {
		cfg.CloudURL = *cloudURL
	}
	cfg.DataDir = actualDataDir
	cfg.PHPBinary = actualPHPBin
	cfg.BackendRoot = actualBackendRoot
	cfg.OfflineMode = *offlineMode
	cfg.LANIP = mainLANIP

	if cfg.TenantSlug != "" {
		log.Printf("[MotorLocal] Empresa activa configurada: '%s' (%s)", cfg.TenantSlug, cfg.CompanyName)
	} else {
		log.Printf("[MotorLocal] Modo multi-empresa dinámico: sin slug forzado (auto-resolución desde sesión/SQLite)")
	}

	targetURL, _ := url.Parse(fmt.Sprintf("http://127.0.0.1:%d", cfg.BackendPort))
	rp := httputil.NewSingleHostReverseProxy(targetURL)
	originalDirector := rp.Director
	rp.Director = func(req *http.Request) {
		clientHost := req.Host
		originalDirector(req)
		if clientHost != "" {
			req.Header.Set("X-Forwarded-Host", clientHost)
		}
		if req.Header.Get("X-Forwarded-Proto") == "" {
			if req.TLS != nil {
				req.Header.Set("X-Forwarded-Proto", "https")
			} else {
				req.Header.Set("X-Forwarded-Proto", "http")
			}
		}
		if req.Header.Get("X-Tenant") == "" && cfg.TenantSlug != "" {
			req.Header.Set("X-Tenant", cfg.TenantSlug)
		}
	}
	rp.ErrorHandler = func(w http.ResponseWriter, req *http.Request, err error) {
		log.Printf("[MotorLocal Proxy Error] %s %s: %v", req.Method, req.URL.Path, err)
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusServiceUnavailable)
		_ = json.NewEncoder(w).Encode(map[string]string{
			"message": "El motor local se está sincronizando o iniciando. Por favor reintenta en un momento.",
		})
	}

	s := &Supervisor{
		cfg:          cfg,
		stopChan:     make(chan struct{}),
		reverseProxy: rp,
	}

	// 1. Start Laravel Backend in background
	go s.superviseBackend()

	// 2. Start Hardware Agents (Printer & Scale) in background
	go s.startHardwareAgents(baseDir)

	// 3. Start LAN Server & Web UI
	go s.startHTTPServer()

	fmt.Println("==================================================================")
	fmt.Println("       SISTEMA DE INVENTARIO - SERVIDOR CENTRAL MOTOR LOCAL       ")
	fmt.Println("==================================================================")
	fmt.Printf(" Servidor en esta PC:  http://localhost:%d\n", cfg.ProxyPort)
	for _, ip := range lanIPs {
		fmt.Printf(" Servidor en Red LAN:  http://%s:%d\n", ip, cfg.ProxyPort)
	}
	fmt.Printf(" Backend Laravel:      http://127.0.0.1:%d\n", cfg.BackendPort)
	fmt.Printf(" Carpeta de datos:     %s\n", cfg.DataDir)
	fmt.Println("==================================================================")
	fmt.Println(" [Info] Cualquier PC o tablet en la tienda puede abrir:")
	fmt.Printf("        http://%s:%d en su navegador para facturar.\n", mainLANIP, cfg.ProxyPort)
	fmt.Println(" Presiona Ctrl+C para detener el servidor.")

	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, os.Interrupt, syscall.SIGTERM)
	<-sigChan

	fmt.Println("\n[Supervisor] Deteniendo motor local y procesos hijos...")
	close(s.stopChan)
	s.stopBackend()
	fmt.Println("[Supervisor] Apagado completo.")
}

func (s *Supervisor) superviseBackend() {
	for {
		select {
		case <-s.stopChan:
			return
		default:
		}

		s.startBackend()

		select {
		case <-s.stopChan:
			return
		case <-time.After(1 * time.Second):
			log.Println("[Supervisor] El backend PHP terminó. Reiniciando en 1s...")
		}
	}
}

func (s *Supervisor) startBackend() {
	s.mu.Lock()

	exePath, _ := os.Executable()
	baseDir := filepath.Dir(exePath)

	// Auto-detect FrankenPHP high-performance multi-threaded server
	frankenBin := ""
	for _, p := range []string{
		filepath.Join(baseDir, "frankenphp", "frankenphp.exe"),
		filepath.Join(baseDir, "frankenphp.exe"),
		filepath.Join(baseDir, "runtime", "frankenphp", "frankenphp.exe"),
	} {
		if _, err := os.Stat(p); err == nil {
			frankenBin = p
			break
		}
	}

	if frankenBin != "" {
		frankenDir := filepath.Dir(frankenBin)
		publicDir := filepath.Join(s.cfg.BackendRoot, "public")
		log.Printf("[Supervisor] Iniciando FrankenPHP multi-worker (Go + Caddy + PHP ZTS): %s", frankenBin)

		cmd := exec.Command(frankenBin,
			"php-server",
			"--root", publicDir,
			"--listen", fmt.Sprintf("127.0.0.1:%d", s.cfg.BackendPort),
		)
		cmd.Dir = s.cfg.BackendRoot
		cmd.Env = mergeEnvironment(os.Environ(),
			"PHPRC="+frankenDir,
			"PATH="+frankenDir+";"+filepath.Join(frankenDir, "ext")+";"+os.Getenv("PATH"),
			"DB_DATABASE="+filepath.Join(s.cfg.DataDir, "database.sqlite"),
			"LARAVEL_STORAGE_PATH="+filepath.Join(s.cfg.DataDir, "storage"),
			"INVENTARIO_APP_KEY_FILE="+filepath.Join(s.cfg.DataDir, "app.key"),
		)
		cmd.Stdout = log.Writer()
		cmd.Stderr = log.Writer()
		setSilentProcess(cmd)

		if err := cmd.Start(); err != nil {
			s.mu.Unlock()
			log.Printf("[Supervisor] Error iniciando FrankenPHP: %v. Reintentando...", err)
			time.Sleep(1 * time.Second)
			return
		}

		s.cmd = cmd
		s.running = true
		s.mu.Unlock()

		_ = cmd.Wait()

		s.mu.Lock()
		s.running = false
		s.cmd = nil
		s.mu.Unlock()
		return
	}

	// Fallback: PHP CLI built-in server
	phpDir := filepath.Dir(s.cfg.PHPBinary)
	iniFile := filepath.Join(phpDir, "php.ini")

	args := []string{}
	if _, err := os.Stat(iniFile); err == nil {
		args = append(args, "-c", iniFile)
	}

	serverScript := filepath.Join(s.cfg.BackendRoot, "server.php")
	if _, err := os.Stat(serverScript); err == nil {
		log.Printf("[Supervisor] Iniciando PHP Built-in Server instantáneo con server.php")
		args = append(args,
			"-S", fmt.Sprintf("127.0.0.1:%d", s.cfg.BackendPort),
			serverScript,
		)
	} else {
		log.Printf("[Supervisor] server.php no encontrado, usando artisan serve --no-reload")
		args = append(args,
			"artisan", "serve",
			"--host=127.0.0.1",
			fmt.Sprintf("--port=%d", s.cfg.BackendPort),
			"--no-reload",
		)
	}

	log.Printf("[Supervisor] Ejecutando PHP: %s (en %s)", s.cfg.PHPBinary, s.cfg.BackendRoot)

	cmd := exec.Command(s.cfg.PHPBinary, args...)
	cmd.Dir = s.cfg.BackendRoot

	// Ensure PATH contains php directory
	phpDirAbs, _ := filepath.Abs(phpDir)
	cmd.Env = mergeEnvironment(os.Environ(),
		"PATH="+phpDirAbs+";"+filepath.Join(phpDirAbs, "ext")+";"+os.Getenv("PATH"),
		"DB_DATABASE="+filepath.Join(s.cfg.DataDir, "database.sqlite"),
		"LARAVEL_STORAGE_PATH="+filepath.Join(s.cfg.DataDir, "storage"),
		"INVENTARIO_APP_KEY_FILE="+filepath.Join(s.cfg.DataDir, "app.key"),
	)

	cmd.Stdout = log.Writer()
	cmd.Stderr = log.Writer()
	setSilentProcess(cmd)

	if err := cmd.Start(); err != nil {
		s.mu.Unlock()
		log.Printf("[Supervisor] Error iniciando backend PHP: %v", err)
		time.Sleep(3 * time.Second)
		return
	}

	s.cmd = cmd
	s.running = true
	s.mu.Unlock()

	_ = cmd.Wait()

	s.mu.Lock()
	s.running = false
	s.cmd = nil
	s.mu.Unlock()
}

func (s *Supervisor) startHardwareAgents(baseDir string) {
	// 1. Printer Agent (Port 17777)
	printerExe := filepath.Join(baseDir, "printer-agent.exe")
	if _, err := os.Stat(printerExe); err == nil {
		log.Printf("[Supervisor] Iniciando Agente de Impresion Termica en http://127.0.0.1:17777")
		cmd := exec.Command(printerExe, "-port=17777", "-bind=127.0.0.1")
		cmd.Dir = baseDir
		setSilentProcess(cmd)
		if err := cmd.Start(); err != nil {
			log.Printf("[Supervisor] Error iniciando printer-agent: %v", err)
		} else {
			s.mu.Lock()
			s.printerCmd = cmd
			s.mu.Unlock()
		}
	}

	// 2. Scale Agent (Port 19999)
	scaleExe := filepath.Join(baseDir, "scale-agent.exe")
	if _, err := os.Stat(scaleExe); err == nil {
		log.Printf("[Supervisor] Iniciando Agente de Balanza Digital en http://127.0.0.1:19999")
		args := []string{
			"-port=19999",
			"-bind=127.0.0.1",
			"-transport=" + s.cfg.ScaleTransport,
			"-device=" + s.cfg.ScaleDevice,
			"-protocol=" + s.cfg.ScaleProtocol,
			fmt.Sprintf("-baud=%d", s.cfg.ScaleBaudRate),
			fmt.Sprintf("-data-bits=%d", s.cfg.ScaleDataBits),
			"-parity=" + s.cfg.ScaleParity,
			fmt.Sprintf("-stop-bits=%g", s.cfg.ScaleStopBits),
			"-tcp-address=" + s.cfg.ScaleTCPAddress,
			"-decimal-separator=" + s.cfg.ScaleDecimalSeparator,
			"-unit=" + s.cfg.ScaleUnit,
		}
		cmd := exec.Command(scaleExe, args...)
		cmd.Dir = baseDir
		setSilentProcess(cmd)
		if err := cmd.Start(); err != nil {
			log.Printf("[Supervisor] Error iniciando scale-agent: %v", err)
		} else {
			s.mu.Lock()
			s.scaleCmd = cmd
			s.mu.Unlock()
		}
	}
}

func (s *Supervisor) stopBackend() {
	s.mu.Lock()
	defer s.mu.Unlock()

	// Termina solo los procesos hijos que este Motor Local inicio.
	for _, child := range []*exec.Cmd{s.cmd, s.printerCmd, s.scaleCmd} {
		if child != nil && child.Process != nil {
			_ = child.Process.Kill()
		}
	}
	s.printerCmd = nil
	s.scaleCmd = nil
	s.running = false
}

func (s *Supervisor) startHTTPServer() {
	subFS, _ := fs.Sub(webFS, "web")
	photosDir := filepath.Join(s.cfg.DataDir, "storage", "app", "public", "products")

	// Helper to configure a multiplexer for either POS or Admin
	createMux := func(entryHtml string) *http.ServeMux {
		mux := http.NewServeMux()

		// Estado local de solo lectura. Las operaciones de restauracion/cambio de empresa
		// quedan deshabilitadas para impedir escrituras y SSRF desde la red LAN.
		mux.HandleFunc("/api/local/status", s.handleLocalStatus)

		// 2. Photos direct from disk
		if _, err := os.Stat(photosDir); err == nil {
			fileServer := http.FileServer(http.Dir(photosDir))
			mux.Handle("/storage/products/", http.StripPrefix("/storage/products/", fileServer))
		}

		// 3. API Proxy to Laravel
		mux.HandleFunc("/api/", func(w http.ResponseWriter, r *http.Request) {
			origin := r.Header.Get("Origin")
			if origin != "" {
				if !isTrustedWebOrigin(origin) {
					http.Error(w, "Origen no permitido.", http.StatusForbidden)
					return
				}
				w.Header().Set("Vary", "Origin")
				w.Header().Set("Access-Control-Allow-Origin", origin)
				w.Header().Set("Access-Control-Allow-Credentials", "true")
				w.Header().Set("Access-Control-Allow-Methods", "GET, POST, PUT, PATCH, DELETE, OPTIONS")
				w.Header().Set("Access-Control-Allow-Headers", "Content-Type, Authorization, X-Requested-With, X-Tenant, Accept, Idempotency-Key, X-Requested-By")
				if r.Header.Get("Access-Control-Request-Private-Network") == "true" {
					w.Header().Set("Access-Control-Allow-Private-Network", "true")
				}
			}
			if r.Method == http.MethodOptions {
				w.WriteHeader(http.StatusOK)
				return
			}
			s.reverseProxy.ServeHTTP(w, r)
		})

		// 4. SPA Web Interface
		if subFS != nil {
			fileServer := http.FileServer(http.FS(subFS))
			mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
				path := strings.TrimPrefix(r.URL.Path, "/")
				if path == "" || path == "index.html" {
					f, err := subFS.Open(entryHtml)
					if err == nil {
						defer f.Close()
						w.Header().Set("Content-Type", "text/html; charset=utf-8")
						_, _ = io.Copy(w, f)
						return
					}
				}

				f, err := subFS.Open(path)
				if err != nil {
					// Fallback to entryHtml for client-side routing
					fFallback, err := subFS.Open(entryHtml)
					if err == nil {
						defer fFallback.Close()
						w.Header().Set("Content-Type", "text/html; charset=utf-8")
						_, _ = io.Copy(w, fFallback)
						return
					}
					http.NotFound(w, r)
					return
				}
				f.Close()

				fileServer.ServeHTTP(w, r)
			})
		}
		return mux
	}

	// 1. Start POS Server on port 8787 (background goroutine)
	posMux := createMux("pos.html")
	posAddr := fmt.Sprintf("0.0.0.0:%d", s.cfg.ProxyPort)
	log.Printf("[MotorLocal] Servidor POS y API escuchando en http://%s", posAddr)
	go func() {
		if err := http.ListenAndServe(posAddr, posMux); err != nil {
			log.Fatalf("[MotorLocal] Error en servidor POS: %v", err)
		}
	}()

	// 2. Start Admin Server on port 8789 (main goroutine)
	adminMux := createMux("admin.html")
	adminAddr := "0.0.0.0:8789"
	log.Printf("[MotorLocal] Servidor Administrativo escuchando en http://%s", adminAddr)
	if err := http.ListenAndServe(adminAddr, adminMux); err != nil {
		log.Fatalf("[MotorLocal] Error en servidor Admin: %v", err)
	}
}

func (s *Supervisor) handleLocalStatus(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "Metodo no permitido.", http.StatusMethodNotAllowed)
		return
	}
	if !isLoopbackRequest(r) {
		http.Error(w, "Este control solo esta disponible en la PC central.", http.StatusForbidden)
		return
	}
	s.handleStatus(w, r)
}

func isLoopbackRequest(r *http.Request) bool {
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		host = r.RemoteAddr
	}
	ip := net.ParseIP(strings.Trim(host, "[]"))
	return ip != nil && ip.IsLoopback()
}

func isTrustedWebOrigin(raw string) bool {
	origin, err := url.Parse(raw)
	if err != nil || origin.Hostname() == "" || origin.User != nil {
		return false
	}
	host := strings.ToLower(origin.Hostname())
	if origin.Scheme == "https" {
		return host == "app.balanzapro.com" && (origin.Port() == "" || origin.Port() == "443")
	}
	if origin.Scheme != "http" {
		return false
	}
	if origin.Port() != "8787" && origin.Port() != "8789" {
		return false
	}
	if host == "localhost" {
		return true
	}
	ip := net.ParseIP(host)
	return ip != nil && (ip.IsLoopback() || ip.IsPrivate())
}

func (s *Supervisor) handleStatus(w http.ResponseWriter, r *http.Request) {
	dbPath := filepath.Join(s.cfg.DataDir, "database.sqlite")
	dbSize := int64(0)
	dbModTime := ""
	dbExists := false
	if st, err := os.Stat(dbPath); err == nil {
		dbExists = true
		dbSize = st.Size()
		dbModTime = st.ModTime().Format("2006-01-02 15:04:05")
	}

	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"status":         "running",
		"proxy_port":     s.cfg.ProxyPort,
		"backend_port":   s.cfg.BackendPort,
		"lan_ip":         s.cfg.LANIP,
		"all_lan_ips":    getLANIPs(),
		"offline_mode":   s.cfg.OfflineMode,
		"tenant_slug":    s.cfg.TenantSlug,
		"cloud_url":      s.cfg.CloudURL,
		"data_dir":       s.cfg.DataDir,
		"db_path":        dbPath,
		"db_exists":      dbExists,
		"db_size_bytes":  dbSize,
		"db_modified_at": dbModTime,
	})
}

func (s *Supervisor) handleToggleOffline(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Offline bool `json:"offline"`
	}
	_ = json.NewDecoder(r.Body).Decode(&req)
	s.cfg.OfflineMode = req.Offline

	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"status":       "success",
		"offline_mode": s.cfg.OfflineMode,
		"message":      fmt.Sprintf("Modo Offline establecido a: %v", s.cfg.OfflineMode),
	})
}

func (s *Supervisor) handleRestoreBackup(w http.ResponseWriter, r *http.Request) {
	var req struct {
		CloudURL   string `json:"cloud_url"`
		TenantSlug string `json:"tenant_slug"`
	}
	_ = json.NewDecoder(r.Body).Decode(&req)

	cloudURL := req.CloudURL
	if cloudURL == "" {
		cloudURL = s.cfg.CloudURL
	}
	slug := req.TenantSlug
	if slug == "" {
		slug = s.cfg.TenantSlug
	}

	downloadURL := fmt.Sprintf("%s/api/offline/package?slug=%s", strings.TrimRight(cloudURL, "/"), slug)
	log.Printf("[BackupRestore] Descargando paquete completo de: %s ...", downloadURL)

	resp, err := http.Get(downloadURL)
	if err != nil {
		http.Error(w, fmt.Sprintf("Error conectando con la nube: %v", err), http.StatusBadGateway)
		return
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(resp.Body)
		http.Error(w, fmt.Sprintf("La nube respondió %d: %s", resp.StatusCode, string(body)), http.StatusBadGateway)
		return
	}

	tmpZip, err := os.CreateTemp("", "restore-*.zip")
	if err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	defer os.Remove(tmpZip.Name())
	defer tmpZip.Close()

	if _, err := io.Copy(tmpZip, resp.Body); err != nil {
		http.Error(w, "Error guardando archivo descargado: "+err.Error(), http.StatusInternalServerError)
		return
	}

	zr, err := zip.OpenReader(tmpZip.Name())
	if err != nil {
		http.Error(w, "Archivo ZIP inválido: "+err.Error(), http.StatusInternalServerError)
		return
	}
	defer zr.Close()

	targetDB := filepath.Join(s.cfg.DataDir, "inventario.sqlite")
	if _, err := os.Stat(filepath.Join(s.cfg.BackendRoot, "database", "database.sqlite")); err == nil {
		targetDB = filepath.Join(s.cfg.BackendRoot, "database", "database.sqlite")
	} else if _, err := os.Stat(filepath.Join(s.cfg.BackendRoot, "storage", "framework", "local-dev.sqlite")); err == nil {
		targetDB = filepath.Join(s.cfg.BackendRoot, "storage", "framework", "local-dev.sqlite")
	}

	targetImagesDir := filepath.Join(s.cfg.BackendRoot, "public", "storage", "products")
	_ = os.MkdirAll(targetImagesDir, 0755)

	imagesExtracted := 0
	dbRestored := false

	for _, f := range zr.File {
		rc, err := f.Open()
		if err != nil {
			continue
		}

		if f.Name == "database.sqlite" {
			outDB, err := os.OpenFile(targetDB, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0644)
			if err == nil {
				_, _ = io.Copy(outDB, rc)
				outDB.Close()
				dbRestored = true
			}
		} else if strings.HasPrefix(f.Name, "images/") {
			imgName := filepath.Base(f.Name)
			if imgName != "" && imgName != "." {
				destImg := filepath.Join(targetImagesDir, imgName)
				outImg, err := os.OpenFile(destImg, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0644)
				if err == nil {
					_, _ = io.Copy(outImg, rc)
					outImg.Close()
					imagesExtracted++
				}
			}
		}
		rc.Close()
	}

	log.Printf("[BackupRestore] ¡Éxito! DB restaurada: %v, Fotos extraídas: %d en %s", dbRestored, imagesExtracted, targetImagesDir)

	if slug != "" {
		s.cfg.TenantSlug = slug
		if cloudURL != "" {
			s.cfg.CloudURL = cloudURL
		}
		exePath, _ := os.Executable()
		baseDir := filepath.Dir(exePath)
		_ = config.SaveConfig(s.cfg, filepath.Join(baseDir, "config.json"))
		_ = config.SaveConfig(s.cfg, filepath.Join(s.cfg.DataDir, "config.json"))
		log.Printf("[BackupRestore] config.json actualizado para tenant: '%s'", slug)
	}

	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"status":           "success",
		"message":          "Respaldo y fotos restaurados con éxito",
		"tenant_slug":      s.cfg.TenantSlug,
		"database_path":    targetDB,
		"images_dir":       targetImagesDir,
		"images_extracted": imagesExtracted,
	})
}

func (s *Supervisor) handleSetCompany(w http.ResponseWriter, r *http.Request) {
	var req struct {
		TenantSlug  string `json:"tenant_slug"`
		CompanyName string `json:"company_name"`
		CloudURL    string `json:"cloud_url"`
	}
	_ = json.NewDecoder(r.Body).Decode(&req)
	if req.TenantSlug == "" {
		http.Error(w, "tenant_slug es requerido", http.StatusBadRequest)
		return
	}
	s.cfg.TenantSlug = req.TenantSlug
	if req.CompanyName != "" {
		s.cfg.CompanyName = req.CompanyName
	}
	if req.CloudURL != "" {
		s.cfg.CloudURL = req.CloudURL
	}

	exePath, _ := os.Executable()
	baseDir := filepath.Dir(exePath)
	_ = config.SaveConfig(s.cfg, filepath.Join(baseDir, "config.json"))
	_ = config.SaveConfig(s.cfg, filepath.Join(s.cfg.DataDir, "config.json"))
	log.Printf("[SetCompany] Empresa configurada a '%s' (%s)", s.cfg.TenantSlug, s.cfg.CompanyName)

	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"status":       "success",
		"tenant_slug":  s.cfg.TenantSlug,
		"company_name": s.cfg.CompanyName,
		"cloud_url":    s.cfg.CloudURL,
		"message":      fmt.Sprintf("Empresa configurada exitosamente a '%s'", s.cfg.TenantSlug),
	})
}
