package worker

import (
	"context"
	"fmt"
	"strings"

	"inventarioarens/sync-daemon/pkg/client"
	"inventarioarens/sync-daemon/pkg/config"
	"inventarioarens/sync-daemon/pkg/storage"
)

// ApplyHandler define la interfaz para aplicar eventos del inbox localmente
type ApplyHandler interface {
	ApplyPending(ctx context.Context, tenantSlug string, limit int) (int, error)
}

// ApplyFunc función adaptadora para ApplyHandler
type ApplyFunc func(ctx context.Context, tenantSlug string, limit int) (int, error)

func (f ApplyFunc) ApplyPending(ctx context.Context, tenantSlug string, limit int) (int, error) {
	return f(ctx, tenantSlug, limit)
}

// SyncSummary resumen de un ciclo de sincronización
type SyncSummary struct {
	TenantSlug string `json:"tenant_slug"`
	Pushed     int    `json:"pushed"`
	Pulled     int    `json:"pulled"`
	Applied    int    `json:"applied"`
	Acked      int    `json:"acked"`
	Failed     int    `json:"failed"`
}

func (s SyncSummary) String() string {
	return fmt.Sprintf("%s: OK (subidos %d, bajados %d, aplicados %d, acked %d, fallos %d)",
		s.TenantSlug, s.Pushed, s.Pulled, s.Applied, s.Acked, s.Failed)
}

// Worker orquesta un ciclo de sync para una empresa
type Worker struct {
	db      *storage.DB
	client  *client.Client
	applier ApplyHandler
}

// New crea una instancia del Worker
func New(db *storage.DB, c *client.Client, applier ApplyHandler) *Worker {
	return &Worker{
		db:      db,
		client:  c,
		applier: applier,
	}
}

// RunTenantSync ejecuta el flujo de sincronización: Push -> Pull -> Store -> Apply -> Ack
func (w *Worker) RunTenantSync(ctx context.Context, tenantSlug string, cfg config.TenantConfig) (SyncSummary, error) {
	summary := SyncSummary{
		TenantSlug: tenantSlug,
	}

	tenantID, err := w.db.GetTenantID(ctx, tenantSlug)
	if err != nil {
		summary.Failed++
		return summary, fmt.Errorf("tenant '%s' error: %w", tenantSlug, err)
	}

	limit := cfg.Limit
	if limit <= 0 {
		limit = 50
	}

	// 1. Fase PUSH: Leer eventos locales y enviar a la nube
	outboxEvents, outboxIDs, err := w.db.GetPendingOutbox(ctx, tenantID, limit)
	if err != nil {
		summary.Failed++
		return summary, fmt.Errorf("failed to fetch local outbox: %w", err)
	}

	if len(outboxEvents) > 0 {
		pushResp, err := w.client.Push(ctx, cfg.CloudURL, cfg.Token, tenantSlug, cfg.NodeCode, outboxEvents)
		if err != nil {
			summary.Failed++
			return summary, fmt.Errorf("cloud push failed: %w", err)
		}

		if err := w.db.MarkOutboxProcessed(ctx, tenantID, outboxIDs); err != nil {
			summary.Failed++
			return summary, fmt.Errorf("failed to mark outbox processed: %w", err)
		}

		summary.Pushed = pushResp.Received
	}

	// 2. Fase PULL: Descargar eventos de la nube
	inboxEvents, err := w.client.Pull(ctx, cfg.CloudURL, cfg.Token, tenantSlug, cfg.NodeCode, limit)
	if err != nil {
		summary.Failed++
		return summary, fmt.Errorf("cloud pull failed: %w", err)
	}

	if len(inboxEvents) > 0 {
		stored, err := w.db.StoreInboxEvents(ctx, tenantID, 0, inboxEvents)
		if err != nil {
			summary.Failed++
			return summary, fmt.Errorf("failed to store inbox events: %w", err)
		}
		summary.Pulled = stored
	}

	// 3. Fase APPLY & ACK: Si hay eventos en inbox o recién descargados
	pendingInboxCount, _ := w.db.CountPendingInbox(ctx, tenantID)
	if (pendingInboxCount > 0 || len(inboxEvents) > 0) && w.applier != nil {
		applied, err := w.applier.ApplyPending(ctx, tenantSlug, limit)
		if err != nil {
			summary.Failed++
			// No detenemos el ack si aplicaron algunos
		}
		summary.Applied = applied

		// Enviar ACK por cada evento descargado
		for _, e := range inboxEvents {
			if strings.TrimSpace(e.EventUUID) == "" {
				continue
			}
			if err := w.client.Ack(ctx, cfg.CloudURL, cfg.Token, tenantSlug, e.EventUUID); err == nil {
				summary.Acked++
			} else {
				summary.Failed++
			}
		}
	}

	return summary, nil
}
