package server

import (
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"balanzapro/scale-agent/pkg/reader"
)

type Server struct {
	port   int
	reader reader.ScaleReader
	mux    *http.ServeMux
}

func NewServer(port int, r reader.ScaleReader) *Server {
	s := &Server{port: port, reader: r, mux: http.NewServeMux()}
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
	origin := r.Header.Get("Origin")
	if origin != "" {
		if !trustedOrigin(origin) {
			writeJSON(w, http.StatusForbidden, map[string]any{"ok": false, "message": "Origen no permitido."})
			return
		}
		w.Header().Set("Access-Control-Allow-Origin", origin)
		w.Header().Add("Vary", "Origin")
	}
	w.Header().Set("Access-Control-Allow-Methods", "GET, OPTIONS")
	w.Header().Set("Access-Control-Allow-Headers", "Content-Type")
	w.Header().Set("Access-Control-Max-Age", "86400")
	if r.Header.Get("Access-Control-Request-Private-Network") == "true" {
		w.Header().Set("Access-Control-Allow-Private-Network", "true")
	}
	if r.Method == http.MethodOptions {
		w.WriteHeader(http.StatusNoContent)
		return
	}
	s.mux.ServeHTTP(w, r)
}

func trustedOrigin(origin string) bool {
	if origin == "https://app.balanzapro.com" {
		return true
	}
	parsed, err := url.Parse(origin)
	if err != nil || parsed.User != nil || parsed.Path != "" || parsed.RawQuery != "" || parsed.Fragment != "" {
		return false
	}
	port, err := strconv.Atoi(parsed.Port())
	if err != nil || (port != 8787 && port != 8789) {
		return false
	}
	host := strings.Trim(parsed.Hostname(), "[]")
	if host == "localhost" || host == "127.0.0.1" || host == "::1" {
		return parsed.Scheme == "http"
	}
	ip := net.ParseIP(host)
	return parsed.Scheme == "http" && ip != nil && (ip.IsPrivate() || ip.IsLoopback())
}

func (s *Server) handleHealth(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		s.handleNotFound(w, r)
		return
	}
	reading := s.reader.GetLatest()
	status := "disconnected"
	if reading != nil && time.Since(reading.Timestamp) < 5*time.Second {
		status = "connected"
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"ok": true, "service": "balanzapro-scale-agent", "port": s.port, "status": status,
	})
}

func (s *Server) handleWeight(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		s.handleNotFound(w, r)
		return
	}
	reading := s.reader.GetLatest()
	if reading == nil {
		writeJSON(w, http.StatusOK, map[string]any{"ok": true, "status": "waiting_for_reading"})
		return
	}
	if time.Since(reading.Timestamp) > 5*time.Second {
		writeJSON(w, http.StatusOK, map[string]any{"ok": true, "status": "stale"})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"ok": true, "status": "ready", "weight": reading.Weight, "unit": reading.Unit,
		"is_stable": reading.IsStable, "raw": reading.Raw,
		"timestamp": reading.Timestamp.Format(time.RFC3339Nano),
	})
}

func (s *Server) handleStream(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		s.handleNotFound(w, r)
		return
	}
	flusher, ok := w.(http.Flusher)
	if !ok {
		http.Error(w, "Streaming no compatible.", http.StatusInternalServerError)
		return
	}
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-cache")
	w.Header().Set("Connection", "keep-alive")
	ch := s.reader.Subscribe()
	defer s.reader.Unsubscribe(ch)
	for {
		select {
		case <-r.Context().Done():
			return
		case reading, ok := <-ch:
			if !ok {
				return
			}
			data, err := json.Marshal(reading)
			if err != nil {
				continue
			}
			_, _ = fmt.Fprintf(w, "data: %s\n\n", data)
			flusher.Flush()
		}
	}
}

func (s *Server) handleNotFound(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusNotFound, map[string]any{"ok": false, "message": "Ruta no encontrada."})
}

func writeJSON(w http.ResponseWriter, status int, data any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(data)
}
