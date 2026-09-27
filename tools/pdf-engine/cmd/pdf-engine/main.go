package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"time"

	"inventarioarens/pdf-engine/pkg/invoice"
	"inventarioarens/pdf-engine/pkg/ticket"
)

func main() {
	docType := flag.String("type", "ticket", "Tipo de documento ('ticket' o 'invoice')")
	input := flag.String("input", "", "Archivo JSON de entrada con los datos")
	output := flag.String("output", "", "Archivo PDF de salida")
	serve := flag.Bool("serve", false, "Iniciar como microservicio HTTP")
	port := flag.Int("port", 15555, "Puerto TCP para modo servidor")
	bind := flag.String("bind", "127.0.0.1", "IP para modo servidor")
	flag.Parse()

	ticketGen := ticket.NewTicketGenerator()
	invoiceGen := invoice.NewInvoiceGenerator()

	if *serve {
		runServer(*bind, *port, ticketGen, invoiceGen)
		return
	}

	if *input != "" && *output != "" {
		dataBytes, err := os.ReadFile(*input)
		if err != nil {
			log.Fatalf("Error leyendo archivo de entrada: %v", err)
		}

		var pdfBytes []byte
		if *docType == "invoice" {
			var invData invoice.InvoiceData
			if err := json.Unmarshal(dataBytes, &invData); err != nil {
				log.Fatalf("Error parseando JSON de factura: %v", err)
			}
			pdfBytes, err = invoiceGen.GeneratePDF(invData)
		} else {
			var tktData ticket.ReceiptData
			if err := json.Unmarshal(dataBytes, &tktData); err != nil {
				log.Fatalf("Error parseando JSON de ticket: %v", err)
			}
			pdfBytes, err = ticketGen.GeneratePDF(tktData)
		}

		if err != nil {
			log.Fatalf("Error generando PDF: %v", err)
		}

		if err := os.WriteFile(*output, pdfBytes, 0644); err != nil {
			log.Fatalf("Error escribiendo archivo de salida: %v", err)
		}

		fmt.Printf("PDF generado exitosamente (%d bytes): %s\n", len(pdfBytes), *output)
		return
	}

	flag.Usage()
}

func runServer(bind string, port int, ticketGen *ticket.TicketGenerator, invoiceGen *invoice.InvoiceGenerator) {
	mux := http.NewServeMux()

	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"ok":      true,
			"service": "pdf-engine",
		})
	})

	mux.HandleFunc("/ticket", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, `{"error":"Method not allowed"}`, http.StatusMethodNotAllowed)
			return
		}

		body, err := io.ReadAll(r.Body)
		if err != nil {
			http.Error(w, `{"error":"Cannot read body"}`, http.StatusBadRequest)
			return
		}
		defer r.Body.Close()

		var data ticket.ReceiptData
		if err := json.Unmarshal(body, &data); err != nil {
			http.Error(w, `{"error":"Invalid ticket JSON"}`, http.StatusBadRequest)
			return
		}

		pdfBytes, err := ticketGen.GeneratePDF(data)
		if err != nil {
			http.Error(w, fmt.Sprintf(`{"error":"%v"}`, err), http.StatusInternalServerError)
			return
		}

		w.Header().Set("Content-Type", "application/pdf")
		w.Header().Set("Content-Disposition", `inline; filename="ticket.pdf"`)
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write(pdfBytes)
	})

	mux.HandleFunc("/invoice", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, `{"error":"Method not allowed"}`, http.StatusMethodNotAllowed)
			return
		}

		body, err := io.ReadAll(r.Body)
		if err != nil {
			http.Error(w, `{"error":"Cannot read body"}`, http.StatusBadRequest)
			return
		}
		defer r.Body.Close()

		var data invoice.InvoiceData
		if err := json.Unmarshal(body, &data); err != nil {
			http.Error(w, `{"error":"Invalid invoice JSON"}`, http.StatusBadRequest)
			return
		}

		pdfBytes, err := invoiceGen.GeneratePDF(data)
		if err != nil {
			http.Error(w, fmt.Sprintf(`{"error":"%v"}`, err), http.StatusInternalServerError)
			return
		}

		w.Header().Set("Content-Type", "application/pdf")
		w.Header().Set("Content-Disposition", `inline; filename="invoice.pdf"`)
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write(pdfBytes)
	})

	addr := fmt.Sprintf("%s:%d", bind, port)
	log.Printf("[PdfEngine] Servidor PDF activo en http://%s", addr)
	server := &http.Server{
		Addr:              addr,
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
	}
	if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		log.Fatalf("Error en servidor: %v", err)
	}
}
