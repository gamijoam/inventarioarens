package server

import (
	"encoding/json"
	"fmt"
	"net/http"
	"time"

	"balanzapro/scale-agent/pkg/reader"
)

type Server struct {
	port   int
	reader reader.ScaleReader
	mux    *http.ServeMux
}

func NewServer(port int, r reader.ScaleReader) *Server {
	s := &Server{
		port:   port,
		reader: r,
		mux:    http.NewServeMux(),
	}
	s.routes()
	return s
}

func (s *Server) routes() {
	s.mux.HandleFunc("/health", s.handleHealth)
	s.mux.HandleFunc("/weight", s.handleWeight)
	s.mux.HandleFunc("/stream", s.handleStream)
	s.mux.HandleFunc("/", s.handleNotFound)
}

func (s *Server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	// CORS Headers
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.Header().Set("Access-Control-Allow-Methods", "GET, OPTIONS")
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
		"service": "balanzapro-scale-agent",
		"port":    s.port,
	})
}

func (s *Server) handleWeight(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		s.handleNotFound(w, r)
		return
	}

	reading := s.reader.GetLatest()
	if reading == nil {
		writeJSON(w, http.StatusOK, map[string]interface{}{
			"ok":        true,
			"weight":    0.0,
			"unit":      "kg",
			"is_stable": true,
			"status":    "waiting_for_reading",
			"timestamp": time.Now().Format(time.RFC3339),
		})
		return
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"ok":        true,
		"weight":    reading.Weight,
		"unit":      reading.Unit,
		"is_stable": reading.IsStable,
		"raw":       reading.Raw,
		"timestamp": reading.Timestamp.Format(time.RFC3339),
	})
}

func (s *Server) handleStream(w http.ResponseWriter, r *http.Request) {
	flusher, ok := w.(http.Flusher)
	if !ok {
		http.Error(w, "Streaming unsupported", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-cache")
	w.Header().Set("Connection", "keep-alive")

	ch := s.reader.Subscribe()
	defer s.reader.Unsubscribe(ch)

	notify := r.Context().Done()

	for {
		select {
		case <-notify:
			return
		case reading, ok := <-ch:
			if !ok {
				return
			}
			data, err := json.Marshal(reading)
			if err != nil {
				continue
			}
			fmt.Fprintf(w, "data: %s\n\n", data)
			flusher.Flush()
		}
	}
}

func (s *Server) handleNotFound(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusNotFound, map[string]interface{}{
		"ok":      false,
		"message": "Ruta no encontrada.",
	})
}

func writeJSON(w http.ResponseWriter, status int, data interface{}) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(data)
}
