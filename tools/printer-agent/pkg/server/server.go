package server

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"runtime"
	"time"

	"inventarioarens/printer-agent/pkg/format"
	"inventarioarens/printer-agent/pkg/printer"
)

type PrinterInterface interface {
	Print(text string, printerName string, options printer.PrintOptions) (*printer.PrintResult, error)
}

type Server struct {
	port    int
	printer PrinterInterface
	mux     *http.ServeMux
}

func NewServer(port int, p PrinterInterface) *Server {
	s := &Server{
		port:    port,
		printer: p,
		mux:     http.NewServeMux(),
	}
	s.routes()
	return s
}

func (s *Server) routes() {
	s.mux.HandleFunc("/health", s.handleHealth)
	s.mux.HandleFunc("/print", s.handlePrint)
	s.mux.HandleFunc("/", s.handleNotFound)
}

func (s *Server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	// CORS Headers for all responses
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.Header().Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
	w.Header().Set("Access-Control-Allow-Headers", "Content-Type")
	w.Header().Set("Access-Control-Max-Age", "86400")

	if r.Method == http.MethodOptions {
		w.WriteHeader(http.StatusNoContent)
		return
	}

	s.mux.ServeHTTP(w, r)
}

func (s *Server) handleHealth(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		s.handleNotFound(w, r)
		return
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"ok":      true,
		"service": "inventarioarens-printer-agent",
		"port":    s.port,
	})
}

type PrintRequestPayload struct {
	Output    string                 `json:"output"`
	Station   map[string]interface{} `json:"station"`
	Payload   map[string]interface{} `json:"payload"`
	JobID     string                 `json:"job_id"`
	Copy      bool                   `json:"copy"`
	PdfBase64 string                 `json:"pdf_base64"`
}

func (s *Server) handlePrint(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		s.handleNotFound(w, r)
		return
	}

	var req PrintRequestPayload
	dec := json.NewDecoder(r.Body)
	if err := dec.Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]interface{}{
			"ok":      false,
			"message": fmt.Sprintf("JSON invalido: %v", err),
		})
		return
	}

	output := req.Output
	if output == "" {
		output = "digital"
	}

	switch output {
	case "digital":
		result, err := s.saveDigital(req)
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]interface{}{
				"ok":      false,
				"message": err.Error(),
			})
			return
		}
		writeJSON(w, http.StatusOK, result)

	case "thermal":
		result, err := s.printThermal(req)
		if err != nil || !result.Ok {
			status := http.StatusInternalServerError
			if result == nil {
				result = &printer.PrintResult{Ok: false, Message: err.Error()}
			}
			writeJSON(w, status, result)
			return
		}
		writeJSON(w, http.StatusOK, result)

	default:
		writeJSON(w, http.StatusBadRequest, map[string]interface{}{
			"ok":      false,
			"message": fmt.Sprintf("output invalido: %s", output),
		})
	}
}

func (s *Server) saveDigital(req PrintRequestPayload) (map[string]interface{}, error) {
	station := req.Station
	ticket := req.Payload

	reqDir, _ := station["digital_directory"].(string)
	baseDir := resolveDigitalDir(reqDir)

	if err := os.MkdirAll(baseDir, 0755); err != nil {
		return nil, fmt.Errorf("no se pudo crear la carpeta digital: %w", err)
	}

	slug := "tenant"
	if t, ok := ticket["tenant"].(map[string]interface{}); ok {
		if s, ok := t["slug"].(string); ok && s != "" {
			slug = s
		}
	}

	orderID := req.JobID
	if orderID == "" {
		orderID = fmt.Sprintf("job_%d", time.Now().UnixNano())
	}
	if p, ok := ticket["pos_order"].(map[string]interface{}); ok {
		if id, ok := p["id"]; ok && id != nil {
			orderID = fmt.Sprintf("%v", id)
		}
	}

	suffix := "original"
	if req.Copy {
		suffix = "copy"
	}
	stamp := time.Now().Format("20060102-150405")
	fileBase := filepath.Join(baseDir, fmt.Sprintf("Ticket-%s-%s-%s-%s", slug, orderID, stamp, suffix))

	if req.PdfBase64 != "" {
		path := fileBase + ".pdf"
		decoded, err := base64.StdEncoding.DecodeString(req.PdfBase64)
		if err != nil {
			return nil, fmt.Errorf("pdf_base64 invalido: %w", err)
		}
		if err := os.WriteFile(path, decoded, 0644); err != nil {
			return nil, fmt.Errorf("error al escribir pdf: %w", err)
		}
		return map[string]interface{}{
			"status":   "generated",
			"pdf_path": path,
		}, nil
	}

	// Fallback text file
	docType, _ := ticket["doc"].(string)
	var text string
	if docType == "report_z" {
		text = format.BuildPlainReportZ(ticket)
	} else {
		text = format.BuildPlainTicket(ticket)
	}

	path := fileBase + ".txt"
	if err := os.WriteFile(path, []byte(text), 0644); err != nil {
		return nil, fmt.Errorf("error al escribir txt: %w", err)
	}

	return map[string]interface{}{
		"status":   "generated",
		"pdf_path": path,
		"message":  "PDF no recibido; se guardo texto de respaldo.",
	}, nil
}

func (s *Server) printThermal(req PrintRequestPayload) (*printer.PrintResult, error) {
	station := req.Station
	ticket := req.Payload

	pType, _ := station["printer_type"].(string)
	if pType == "" {
		pType = "windows_printer"
	}

	pName, _ := station["printer_name"].(string)
	nHost, _ := station["network_host"].(string)
	nPort := 9100
	if p, ok := station["network_port"].(float64); ok && p > 0 {
		nPort = int(p)
	}

	profile, _ := ticket["profile"].(map[string]interface{})
	cutPaper, _ := profile["cut_paper"].(bool)
	openDrawer, _ := profile["open_cash_drawer"].(bool)

	docType, _ := ticket["doc"].(string)
	var text string
	if docType == "report_z" {
		text = format.BuildPlainReportZ(ticket)
	} else {
		text = format.BuildPlainTicket(ticket)
	}

	opts := printer.PrintOptions{
		PrinterType:    pType,
		NetworkHost:    nHost,
		NetworkPort:    nPort,
		CutPaper:       cutPaper,
		OpenCashDrawer: openDrawer,
	}

	return s.printer.Print(text, pName, opts)
}

func (s *Server) handleNotFound(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusNotFound, map[string]interface{}{
		"ok":      false,
		"message": "Ruta no encontrada.",
	})
}

func resolveDigitalDir(requested string) string {
	home, _ := os.UserHomeDir()
	if home == "" {
		home = os.TempDir()
	}

	if requested == "" {
		return filepath.Join(home, "Desktop", "Tickets")
	}

	if filepath.IsAbs(requested) {
		return requested
	}

	if runtime.GOOS == "windows" {
		if len(requested) > 2 && requested[1] == ':' {
			return requested
		}
	}

	return filepath.Join(home, requested)
}

func writeJSON(w http.ResponseWriter, status int, data interface{}) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(data)
}
