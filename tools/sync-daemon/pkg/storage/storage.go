package storage

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"strconv"
	"strings"
	"time"

	_ "modernc.org/sqlite"

	"inventarioarens/sync-daemon/pkg/client"
)

// DB envuelve la conexión a SQLite
type DB struct {
	conn *sql.DB
}

// Open abre la base de datos SQLite con los pragmas de alto rendimiento
func Open(dataSourceName string) (*DB, error) {
	// Pragmas recomendados para WAL y concurrencia sin bloqueos
	var dsn string
	if dataSourceName == ":memory:" {
		dsn = ":memory:"
	} else {
		separator := "?"
		if strings.Contains(dataSourceName, "?") {
			separator = "&"
		}
		dsn = fmt.Sprintf("%s%s_pragma=busy_timeout(15000)&_pragma=journal_mode(WAL)&_pragma=synchronous(NORMAL)", dataSourceName, separator)
	}

	db, err := sql.Open("sqlite", dsn)
	if err != nil {
		return nil, fmt.Errorf("failed to open sqlite database: %w", err)
	}

	// Limitar pool de conexiones SQLite a 1 escritor / múltiples lectores seguros
	db.SetMaxOpenConns(5)
	db.SetMaxIdleConns(5)
	db.SetConnMaxLifetime(time.Hour)

	return &DB{conn: db}, nil
}

// Close cierra la conexión a SQLite
func (db *DB) Close() error {
	return db.conn.Close()
}

// Exec ejecuta una sentencia SQL cruda (útil para migraciones/pruebas)
func (db *DB) Exec(query string) error {
	_, err := db.conn.Exec(query)
	return err
}

// GetTenantID obtiene el ID numérico de la empresa a partir de su slug
func (db *DB) GetTenantID(ctx context.Context, slug string) (int64, error) {
	var id int64
	err := db.conn.QueryRowContext(ctx, "SELECT id FROM tenants WHERE slug = ? LIMIT 1", slug).Scan(&id)
	if err != nil {
		return 0, fmt.Errorf("tenant '%s' not found: %w", slug, err)
	}
	return id, nil
}

// GetPendingOutbox obtiene los eventos locales pendientes de sincronizar a la nube
func (db *DB) GetPendingOutbox(ctx context.Context, tenantID int64, limit int) ([]client.OutboxEvent, []int64, error) {
	query := `
		SELECT id, event_uuid, event_type, aggregate_type, COALESCE(aggregate_id, 0), payload, occurred_at
		FROM sync_outbox
		WHERE tenant_id = ?
		  AND status = 'pending'
		  AND (available_at IS NULL OR available_at <= datetime('now'))
		ORDER BY id ASC
		LIMIT ?
	`
	rows, err := db.conn.QueryContext(ctx, query, tenantID, limit)
	if err != nil {
		return nil, nil, fmt.Errorf("failed to query sync_outbox: %w", err)
	}
	defer rows.Close()

	var events []client.OutboxEvent
	var ids []int64

	for rows.Next() {
		var id int64
		var eventUUID, eventType, aggregateType, occurredAt, payloadRaw string
		var aggregateID int64

		if err := rows.Scan(&id, &eventUUID, &eventType, &aggregateType, &aggregateID, &payloadRaw, &occurredAt); err != nil {
			return nil, nil, err
		}

		var payloadMap map[string]interface{}
		_ = json.Unmarshal([]byte(payloadRaw), &payloadMap)

		events = append(events, client.OutboxEvent{
			EventUUID:     eventUUID,
			EventType:     eventType,
			AggregateType: aggregateType,
			AggregateID:   strconv.FormatInt(aggregateID, 10),
			Payload:       payloadMap,
			OccurredAt:    occurredAt,
		})
		ids = append(ids, id)
	}

	return events, ids, nil
}

// MarkOutboxProcessed marca los eventos locales como procesados exitosamente
func (db *DB) MarkOutboxProcessed(ctx context.Context, tenantID int64, ids []int64) error {
	if len(ids) == 0 {
		return nil
	}

	placeholders := make([]string, len(ids))
	args := make([]interface{}, len(ids)+1)
	args[0] = tenantID

	for i, id := range ids {
		placeholders[i] = "?"
		args[i+1] = id
	}

	query := fmt.Sprintf(`
		UPDATE sync_outbox
		SET status = 'processed',
		    processed_at = datetime('now')
		WHERE tenant_id = ? AND id IN (%s)
	`, strings.Join(placeholders, ","))

	_, err := db.conn.ExecContext(ctx, query, args...)
	return err
}

// StoreInboxEvents inserta los eventos descargados de la nube en sync_inbox ignorando duplicados
func (db *DB) StoreInboxEvents(ctx context.Context, tenantID, originNodeID int64, events []client.InboxEvent) (int, error) {
	if len(events) == 0 {
		return 0, nil
	}

	tx, err := db.conn.BeginTx(ctx, nil)
	if err != nil {
		return 0, err
	}
	defer tx.Rollback()

	stmt, err := tx.PrepareContext(ctx, `
		INSERT OR IGNORE INTO sync_inbox (
			tenant_id, origin_node_id, event_uuid, event_type, aggregate_type, aggregate_id, payload, status, received_at
		) VALUES (?, ?, ?, ?, ?, ?, ?, 'received', datetime('now'))
	`)
	if err != nil {
		return 0, err
	}
	defer stmt.Close()

	storedCount := 0
	for _, event := range events {
		payloadBytes, _ := json.Marshal(event.Payload)
		res, err := stmt.ExecContext(ctx, tenantID, originNodeID, event.EventUUID, event.EventType, event.AggregateType, event.AggregateID, string(payloadBytes))
		if err != nil {
			return storedCount, err
		}
		rowsAffected, _ := res.RowsAffected()
		if rowsAffected > 0 {
			storedCount++
		}
	}

	if err := tx.Commit(); err != nil {
		return 0, err
	}

	return storedCount, nil
}

// CountPendingInbox cuenta cuántos eventos hay pendientes de aplicar en sync_inbox
func (db *DB) CountPendingInbox(ctx context.Context, tenantID int64) (int, error) {
	var count int
	err := db.conn.QueryRowContext(ctx, "SELECT count(*) FROM sync_inbox WHERE tenant_id = ? AND (status = 'received' OR status = 'pending')", tenantID).Scan(&count)
	return count, err
}
