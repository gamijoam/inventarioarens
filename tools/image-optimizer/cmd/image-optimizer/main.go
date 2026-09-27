package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"inventarioarens/image-optimizer/pkg/optimizer"
)

func main() {
	input := flag.String("input", "", "Ruta a la imagen de entrada")
	output := flag.String("output", "", "Ruta a la imagen de salida optimizada")
	dir := flag.String("dir", "", "Directorio recursivo a optimizar")
	maxWidth := flag.Int("max-width", 1200, "Ancho máximo en píxeles")
	quality := flag.Int("quality", 80, "Calidad de compresión (1-100)")
	serve := flag.Bool("serve", false, "Iniciar como microservicio HTTP")
	port := flag.Int("port", 14444, "Puerto TCP para modo servidor")
	bind := flag.String("bind", "127.0.0.1", "IP para modo servidor")
	flag.Parse()

	opt := optimizer.NewOptimizer()
	opts := optimizer.Options{
		MaxWidth: *maxWidth,
		Quality:  *quality,
	}

	if *serve {
		runServer(*bind, *port, opt, opts)
		return
	}

	if *input != "" {
		outPath := *output
		if outPath == "" {
			outPath = *input
		}
		res, err := opt.OptimizeFile(*input, outPath, opts)
		if err != nil {
			log.Fatalf("Error optimizando imagen: %v", err)
		}
		fmt.Printf("Optimizado: %s -> %dx%d (%d KB -> %d KB, ratio: %.1f%%)\n",
			*input, res.Width, res.Height, res.BytesIn/1024, res.BytesOut/1024, res.Ratio*100)
		return
	}

	if *dir != "" {
		optimizeDirectory(*dir, opt, opts)
		return
	}

	flag.Usage()
}

func runServer(bind string, port int, opt *optimizer.Optimizer, defaultOpts optimizer.Options) {
	mux := http.NewServeMux()

	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"ok":      true,
			"service": "image-optimizer",
		})
	})

	mux.HandleFunc("/optimize", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, `{"error":"Method not allowed"}`, http.StatusMethodNotAllowed)
			return
		}

		var req struct {
			InputPath  string `json:"input_path"`
			OutputPath string `json:"output_path"`
			MaxWidth   int    `json:"max_width"`
			Quality    int    `json:"quality"`
		}

		if err := json.NewDecoder(r.Body).Decode(&req); err != nil || req.InputPath == "" {
			http.Error(w, `{"error":"input_path is required"}`, http.StatusBadRequest)
			return
		}

		outPath := req.OutputPath
		if outPath == "" {
			outPath = req.InputPath
		}

		opts := defaultOpts
		if req.MaxWidth > 0 {
			opts.MaxWidth = req.MaxWidth
		}
		if req.Quality > 0 {
			opts.Quality = req.Quality
		}

		res, err := opt.OptimizeFile(req.InputPath, outPath, opts)
		if err != nil {
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(http.StatusInternalServerError)
			_ = json.NewEncoder(w).Encode(map[string]interface{}{
				"ok":    false,
				"error": err.Error(),
			})
			return
		}

		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"ok":     true,
			"result": res,
			"output": outPath,
		})
	})

	addr := fmt.Sprintf("%s:%d", bind, port)
	log.Printf("[ImageOptimizer] Servidor activo en http://%s", addr)
	server := &http.Server{
		Addr:              addr,
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
	}
	if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		log.Fatalf("Error en servidor: %v", err)
	}
}

func optimizeDirectory(root string, opt *optimizer.Optimizer, opts optimizer.Options) {
	count := 0
	var savedBytes int64

	_ = filepath.Walk(root, func(path string, info os.FileInfo, err error) error {
		if err != nil || info.IsDir() {
			return nil
		}

		ext := strings.ToLower(filepath.Ext(path))
		if ext == ".jpg" || ext == ".jpeg" || ext == ".png" {
			res, err := opt.OptimizeFile(path, path, opts)
			if err == nil {
				count++
				savedBytes += (res.BytesIn - res.BytesOut)
				fmt.Printf("[%d] %s: %d KB -> %d KB\n", count, filepath.Base(path), res.BytesIn/1024, res.BytesOut/1024)
			}
		}
		return nil
	})

	fmt.Printf("Total optimizados: %d imágenes. Espacio ahorrado: %.2f MB\n", count, float64(savedBytes)/(1024*1024))
}
