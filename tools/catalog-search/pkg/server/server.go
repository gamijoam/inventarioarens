package server

import (
	"encoding/json"
	"net/http"
	"strconv"
	"time"

	"balanzapro/catalog-search/pkg/index"
)

type Server struct {
	port  int
	index *index.CatalogIndex
	mux   *http.ServeMux
}

func NewServer(port int, idx *index.CatalogIndex) *Server {
	s := &Server{
		port:  port,
		index: idx,
		mux:   http.NewServeMux(),
	}
	s.routes()
	return s
}

func (s *Server) routes() {
	s.mux.HandleFunc("/health", s.handleHealth)
	s.mux.HandleFunc("/search", s.handleSearch)
	s.mux.HandleFunc("/barcode", s.handleBarcode)
	s.mux.HandleFunc("/index", s.handleIndex)
	s.mux.HandleFunc("/", s.handleNotFound)
}

func (s *Server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	// CORS Headers
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
	writeJSON(w, http.StatusOK, map[string]interface{}{
		"ok":      true,
		"service": "catalog-search",
		"port":    s.port,
		"count":   s.index.Count(),
	})
}

func (s *Server) handleSearch(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		s.handleNotFound(w, r)
		return
	}

	start := time.Now()
	q := r.URL.Query().Get("q")
	tenantID, _ := strconv.Atoi(r.URL.Query().Get("tenant_id"))
	limit, _ := strconv.Atoi(r.URL.Query().Get("limit"))
	if limit <= 0 {
		limit = 20
	}

	results := s.index.Search(q, tenantID, limit)
	elapsed := time.Since(start).Microseconds()

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"ok":         true,
		"query":      q,
		"results":    results,
		"count":      len(results),
		"elapsed_us": elapsed,
	})
}

func (s *Server) handleBarcode(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		s.handleNotFound(w, r)
		return
	}

	code := r.URL.Query().Get("code")
	tenantID, _ := strconv.Atoi(r.URL.Query().Get("tenant_id"))

	p := s.index.GetByBarcode(code, tenantID)
	if p == nil {
		writeJSON(w, http.StatusNotFound, map[string]interface{}{
			"ok":      false,
			"message": "Producto no encontrado por codigo de barra.",
		})
		return
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"ok":      true,
		"product": p,
	})
}

func (s *Server) handleIndex(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		s.handleNotFound(w, r)
		return
	}

	var products []index.Product
	if err := json.NewDecoder(r.Body).Decode(&products); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]interface{}{
			"ok":      false,
			"message": "JSON invalido.",
		})
		return
	}

	for _, p := range products {
		if p.ID > 0 {
			s.index.AddOrUpdate(p)
		}
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"ok":      true,
		"indexed": len(products),
		"total":   s.index.Count(),
	})
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
