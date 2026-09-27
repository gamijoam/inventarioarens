package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"net/http"
	"os"
	"strconv"
	"time"

	"inventarioarens/barcode-engine/pkg/barcode"
)

func main() {
	bcType := flag.String("type", "code128", "Tipo: 'code128', 'qr', 'scale'")
	text := flag.String("text", "", "Texto o contenido del código de barras o QR")
	item := flag.String("item", "", "Código de producto para balanza (1-5 dígitos)")
	weight := flag.Int("weight", 0, "Peso en gramos para balanza (ej. 1250 para 1.25 kg)")
	output := flag.String("output", "", "Archivo PNG de salida")
	width := flag.Int("width", 300, "Ancho en píxeles")
	height := flag.Int("height", 100, "Alto en píxeles")
	serve := flag.Bool("serve", false, "Iniciar como microservicio HTTP")
	port := flag.Int("port", 13333, "Puerto TCP para modo servidor")
	bind := flag.String("bind", "127.0.0.1", "IP para modo servidor")
	flag.Parse()

	if *serve {
		runServer(*bind, *port)
		return
	}

	if *output != "" {
		var pngBytes []byte
		var err error

		switch *bcType {
		case "qr":
			pngBytes, err = barcode.GenerateQRCode(*text, *width)
		case "scale":
			var eanStr string
			eanStr, pngBytes, err = barcode.GenerateScaleBarcode(*item, *weight, *width, *height)
			fmt.Printf("Código EAN-13 generado: %s\n", eanStr)
		default:
			pngBytes, err = barcode.GenerateCode128(*text, *width, *height)
		}

		if err != nil {
			log.Fatalf("Error generando código: %v", err)
		}

		if err := os.WriteFile(*output, pngBytes, 0644); err != nil {
			log.Fatalf("Error guardando imagen: %v", err)
		}

		fmt.Printf("Código generado exitosamente: %s (%d bytes)\n", *output, len(pngBytes))
		return
	}

	flag.Usage()
}

func runServer(bind string, port int) {
	mux := http.NewServeMux()

	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"ok":      true,
			"service": "barcode-engine",
		})
	})

	mux.HandleFunc("/barcode", func(w http.ResponseWriter, r *http.Request) {
		text := r.URL.Query().Get("text")
		if text == "" {
			http.Error(w, `{"error":"text parameter required"}`, http.StatusBadRequest)
			return
		}
		wPx, _ := strconv.Atoi(r.URL.Query().Get("width"))
		hPx, _ := strconv.Atoi(r.URL.Query().Get("height"))
		if wPx <= 0 {
			wPx = 300
		}
		if hPx <= 0 {
			hPx = 100
		}

		pngBytes, err := barcode.GenerateCode128(text, wPx, hPx)
		if err != nil {
			http.Error(w, fmt.Sprintf(`{"error":"%v"}`, err), http.StatusInternalServerError)
			return
		}

		w.Header().Set("Content-Type", "image/png")
		_, _ = w.Write(pngBytes)
	})

	mux.HandleFunc("/qr", func(w http.ResponseWriter, r *http.Request) {
		text := r.URL.Query().Get("text")
		if text == "" {
			http.Error(w, `{"error":"text parameter required"}`, http.StatusBadRequest)
			return
		}
		size, _ := strconv.Atoi(r.URL.Query().Get("size"))
		if size <= 0 {
			size = 200
		}

		pngBytes, err := barcode.GenerateQRCode(text, size)
		if err != nil {
			http.Error(w, fmt.Sprintf(`{"error":"%v"}`, err), http.StatusInternalServerError)
			return
		}

		w.Header().Set("Content-Type", "image/png")
		_, _ = w.Write(pngBytes)
	})

	mux.HandleFunc("/scale", func(w http.ResponseWriter, r *http.Request) {
		item := r.URL.Query().Get("item")
		weight, _ := strconv.Atoi(r.URL.Query().Get("weight"))
		wPx, _ := strconv.Atoi(r.URL.Query().Get("width"))
		hPx, _ := strconv.Atoi(r.URL.Query().Get("height"))
		if wPx <= 0 {
			wPx = 300
		}
		if hPx <= 0 {
			hPx = 100
		}

		eanStr, pngBytes, err := barcode.GenerateScaleBarcode(item, weight, wPx, hPx)
		if err != nil {
			http.Error(w, fmt.Sprintf(`{"error":"%v"}`, err), http.StatusInternalServerError)
			return
		}

		w.Header().Set("Content-Type", "image/png")
		w.Header().Set("X-Barcode-EAN", eanStr)
		_, _ = w.Write(pngBytes)
	})

	addr := fmt.Sprintf("%s:%d", bind, port)
	log.Printf("[BarcodeEngine] Servidor de códigos de barra activo en http://%s", addr)
	server := &http.Server{
		Addr:              addr,
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
	}
	if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		log.Fatalf("Error en servidor: %v", err)
	}
}
