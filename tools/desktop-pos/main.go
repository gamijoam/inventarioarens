package main

import (
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"
	"unsafe"

	webview2 "github.com/jchv/go-webview2"
	"golang.org/x/sys/windows"
)

func showMessageBox(title, message string) {
	user32 := windows.NewLazyDLL("user32.dll")
	proc := user32.NewProc("MessageBoxW")
	t, _ := windows.UTF16PtrFromString(title)
	m, _ := windows.UTF16PtrFromString(message)
	proc.Call(0, uintptr(unsafe.Pointer(m)), uintptr(unsafe.Pointer(t)), 0x00000010) // MB_ICONERROR
}

func ensureMotorLocal(exeDir string, serverURL string) {
	client := &http.Client{Timeout: 1 * time.Second}
	statusURL := fmt.Sprintf("%s/api/local/status", serverURL)

	resp, err := client.Get(statusURL)
	if err == nil && resp.StatusCode == http.StatusOK {
		resp.Body.Close()
		log.Println("[POS] Servidor MotorLocal detectado y activo.")
		return
	}
	if resp != nil {
		resp.Body.Close()
	}

	motorExe := filepath.Join(exeDir, "MotorLocal.exe")
	if _, err := os.Stat(motorExe); err != nil {
		log.Printf("[POS] No se encontró %s local para auto-inicio", motorExe)
		return
	}

	log.Printf("[POS] MotorLocal no está activo. Iniciando %s en segundo plano...", motorExe)
	cmd := exec.Command(motorExe)
	cmd.Dir = exeDir
	setSilentProcess(cmd)
	if err := cmd.Start(); err != nil {
		log.Printf("[POS] Error iniciando MotorLocal: %v", err)
		return
	}

	// Wait up to 6 seconds for MotorLocal to become ready
	for i := 0; i < 24; i++ {
		time.Sleep(250 * time.Millisecond)
		resp, err := client.Get(statusURL)
		if err == nil && resp.StatusCode == http.StatusOK {
			resp.Body.Close()
			log.Println("[POS] ¡MotorLocal listo!")
			return
		}
		if resp != nil {
			resp.Body.Close()
		}
	}
}

func main() {
	exePath, _ := os.Executable()
	exeDir := filepath.Dir(exePath)

	logFile, err := os.OpenFile(filepath.Join(exeDir, "pos-debug.log"), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0666)
	if err == nil {
		log.SetOutput(io.MultiWriter(os.Stderr, logFile))
		defer logFile.Close()
	}

	server := flag.String("server", "http://localhost:8787", "URL del servidor MotorLocal")
	debug := flag.Bool("debug", false, "Habilitar DevTools")
	width := flag.Int("width", 1280, "Ancho de la ventana")
	height := flag.Int("height", 800, "Alto de la ventana")
	flag.Parse()

	log.Printf("[POS] Conectando a servidor central: %s", *server)

	// Ensure MotorLocal is running if targeting local machine
	if strings.Contains(*server, "localhost:8787") || strings.Contains(*server, "127.0.0.1:8787") {
		ensureMotorLocal(exeDir, "http://localhost:8787")
	}

	localAppData := os.Getenv("LOCALAPPDATA")
	if localAppData == "" {
		localAppData = os.TempDir()
	}
	dataPath := filepath.Join(localAppData, "BalanzaPro", "POS-WebView2")
	_ = os.MkdirAll(dataPath, 0755)

	w := webview2.NewWithOptions(webview2.WebViewOptions{
		Debug:     *debug,
		AutoFocus: true,
		DataPath:  dataPath,
		WindowOptions: webview2.WindowOptions{
			Title:  "BalanzaPro POS",
			Width:  uint(*width),
			Height: uint(*height),
			IconId: 0,
			Center: true,
		},
	})

	if w == nil {
		setupExe := filepath.Join(exeDir, "MicrosoftEdgeWebview2Setup.exe")
		msg := "No se pudo iniciar la interfaz de escritorio de BalanzaPro.\n\nEs necesario instalar Microsoft Edge WebView2 Runtime en esta PC."
		if _, err := os.Stat(setupExe); err == nil {
			msg += "\n\nSe iniciará el instalador oficial 'MicrosoftEdgeWebview2Setup.exe' incluido en la carpeta para instalarlo ahora."
			showMessageBox("BalanzaPro POS - WebView2 Requerido", msg)
			_ = exec.Command(setupExe).Start()
		} else {
			msg += "\n\nPuedes descargarlo gratuitamente desde el sitio oficial de Microsoft."
			showMessageBox("BalanzaPro POS - WebView2 Requerido", msg)
		}
		os.Exit(1)
	}
	defer w.Destroy()

	w.Navigate(*server)
	w.Run()
}
