package client

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

// Options configuración para el cliente HTTP
type Options struct {
	Timeout time.Duration
}

// Client cliente HTTP optimizado para sincronización
type Client struct {
	httpClient *http.Client
}

// New crea una instancia de Client con keep-alives y timeout
func New(opts Options) *Client {
	timeout := opts.Timeout
	if timeout <= 0 {
		timeout = 30 * time.Second
	}

	transport := &http.Transport{
		MaxIdleConns:        100,
		MaxIdleConnsPerHost: 20,
		IdleConnTimeout:     90 * time.Second,
		DisableCompression: false,
	}

	return &Client{
		httpClient: &http.Client{
			Transport: transport,
			Timeout:   timeout,
		},
	}
}

// OutboxEvent representa un evento a subir a la nube
type OutboxEvent struct {
	EventUUID     string                 `json:"event_uuid"`
	EventType     string                 `json:"event_type"`
	AggregateType string                 `json:"aggregate_type"`
	AggregateID   string                 `json:"aggregate_id"`
	Payload       map[string]interface{} `json:"payload"`
	OccurredAt    string                 `json:"occurred_at"`
}

// PushResponse respuesta de la nube al push
type PushResponse struct {
	Received   int `json:"received"`
	Duplicated int `json:"duplicated"`
}

// InboxEvent representa un evento descargado de la nube
type InboxEvent struct {
	ID            int64                  `json:"id"`
	EventUUID     string                 `json:"event_uuid"`
	EventType     string                 `json:"event_type"`
	AggregateType string                 `json:"aggregate_type"`
	AggregateID   string                 `json:"aggregate_id"`
	Payload       map[string]interface{} `json:"payload"`
	OccurredAt    string                 `json:"occurred_at"`
}

func buildURL(baseURL, endpoint string) string {
	cleanBase := strings.TrimRight(baseURL, "/")
	cleanEndpoint := strings.TrimLeft(endpoint, "/")
	return cleanBase + "/" + cleanEndpoint
}

// Push envía eventos locales a la nube
func (c *Client) Push(ctx context.Context, cloudURL, token, tenantSlug, nodeCode string, events []OutboxEvent) (*PushResponse, error) {
	u := buildURL(cloudURL, "sync/events/push")

	payload := map[string]interface{}{
		"origin_node_code": nodeCode,
		"events":           events,
	}

	data, err := json.Marshal(payload)
	if err != nil {
		return nil, fmt.Errorf("failed to marshal push payload: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, u, bytes.NewReader(data))
	if err != nil {
		return nil, err
	}

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Authorization", "Bearer "+token)
	if tenantSlug != "" {
		req.Header.Set("X-Tenant", tenantSlug)
	}

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("HTTP request failed: %w", err)
	}
	defer resp.Body.Close()

	bodyBytes, _ := io.ReadAll(resp.Body)

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, fmt.Errorf("cloud push returned HTTP %d: %s", resp.StatusCode, string(bodyBytes))
	}

	var parsed struct {
		Data PushResponse `json:"data"`
	}
	if err := json.Unmarshal(bodyBytes, &parsed); err != nil {
		return &PushResponse{Received: len(events), Duplicated: 0}, nil
	}

	return &parsed.Data, nil
}

// Pull consulta eventos pendientes en la nube para este nodo
func (c *Client) Pull(ctx context.Context, cloudURL, token, tenantSlug, nodeCode string, limit int) ([]InboxEvent, error) {
	rawURL := buildURL(cloudURL, "sync/events/pull")
	parsedURL, err := url.Parse(rawURL)
	if err != nil {
		return nil, err
	}

	q := parsedURL.Query()
	q.Set("node_code", nodeCode)
	q.Set("limit", strconv.Itoa(limit))
	parsedURL.RawQuery = q.Encode()

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, parsedURL.String(), nil)
	if err != nil {
		return nil, err
	}

	req.Header.Set("Accept", "application/json")
	req.Header.Set("Authorization", "Bearer "+token)
	if tenantSlug != "" {
		req.Header.Set("X-Tenant", tenantSlug)
	}

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("HTTP pull failed: %w", err)
	}
	defer resp.Body.Close()

	bodyBytes, _ := io.ReadAll(resp.Body)

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, fmt.Errorf("cloud pull returned HTTP %d: %s", resp.StatusCode, string(bodyBytes))
	}

	var parsed struct {
		Data []InboxEvent `json:"data"`
	}
	if err := json.Unmarshal(bodyBytes, &parsed); err != nil {
		return nil, fmt.Errorf("failed to parse pull response: %w", err)
	}

	return parsed.Data, nil
}

// Ack confirma la recepción de un evento a la nube
func (c *Client) Ack(ctx context.Context, cloudURL, token, tenantSlug, eventUUID string) error {
	u := buildURL(cloudURL, fmt.Sprintf("sync/events/%s/ack", eventUUID))

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, u, nil)
	if err != nil {
		return err
	}

	req.Header.Set("Accept", "application/json")
	req.Header.Set("Authorization", "Bearer "+token)
	if tenantSlug != "" {
		req.Header.Set("X-Tenant", tenantSlug)
	}

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("HTTP ack failed: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		bodyBytes, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("cloud ack returned HTTP %d: %s", resp.StatusCode, string(bodyBytes))
	}

	return nil
}
