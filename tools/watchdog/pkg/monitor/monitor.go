package monitor

import (
	"encoding/json"
	"fmt"
	"net/http"
	"os/exec"
	"strings"
	"sync"
	"time"
)

type Target struct {
	Name           string `json:"name"`
	URL            string `json:"url"`
	RestartCommand string `json:"restart_command"`
	TimeoutSec     int    `json:"timeout_sec"`
}

type TargetStatus struct {
	TargetName          string        `json:"target_name"`
	Healthy             bool          `json:"healthy"`
	StatusCode          int           `json:"status_code"`
	Latency             time.Duration `json:"latency_ms"`
	ConsecutiveFailures int           `json:"consecutive_failures"`
	Error               string        `json:"error,omitempty"`
}

type Monitor struct {
	mu       sync.Mutex
	failures map[string]int
}

func NewMonitor() *Monitor {
	return &Monitor{
		failures: make(map[string]int),
	}
}

func (m *Monitor) CheckTarget(t Target) TargetStatus {
	timeout := time.Duration(t.TimeoutSec) * time.Second
	if timeout <= 0 {
		timeout = 2 * time.Second
	}

	client := &http.Client{Timeout: timeout}
	start := time.Now()

	resp, err := client.Get(t.URL)
	latency := time.Since(start)

	m.mu.Lock()
	defer m.mu.Unlock()

	if err != nil {
		m.failures[t.Name]++
		return TargetStatus{
			TargetName:          t.Name,
			Healthy:             false,
			StatusCode:          0,
			Latency:             latency,
			ConsecutiveFailures: m.failures[t.Name],
			Error:               err.Error(),
		}
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		m.failures[t.Name]++
		return TargetStatus{
			TargetName:          t.Name,
			Healthy:             false,
			StatusCode:          resp.StatusCode,
			Latency:             latency,
			ConsecutiveFailures: m.failures[t.Name],
			Error:               fmt.Sprintf("HTTP status %d", resp.StatusCode),
		}
	}

	// Verify JSON contains ok: true if JSON response
	var body map[string]interface{}
	if err := json.NewDecoder(resp.Body).Decode(&body); err == nil {
		if okVal, exists := body["ok"]; exists {
			if okBool, isBool := okVal.(bool); isBool && !okBool {
				m.failures[t.Name]++
				return TargetStatus{
					TargetName:          t.Name,
					Healthy:             false,
					StatusCode:          resp.StatusCode,
					Latency:             latency,
					ConsecutiveFailures: m.failures[t.Name],
					Error:               "response ok=false",
				}
			}
		}
	}

	// Healthy: reset failures counter
	m.failures[t.Name] = 0

	return TargetStatus{
		TargetName:          t.Name,
		Healthy:             true,
		StatusCode:          resp.StatusCode,
		Latency:             latency,
		ConsecutiveFailures: 0,
	}
}

// AutoHeal runs the restart command if failures >= threshold
func (m *Monitor) AutoHeal(t Target, threshold int) (bool, error) {
	m.mu.Lock()
	fails := m.failures[t.Name]
	m.mu.Unlock()

	if fails < threshold || t.RestartCommand == "" {
		return false, nil
	}

	parts := strings.Fields(t.RestartCommand)
	if len(parts) == 0 {
		return false, nil
	}

	cmd := exec.Command(parts[0], parts[1:]...)
	err := cmd.Run()
	if err == nil {
		m.mu.Lock()
		m.failures[t.Name] = 0 // Reset after restart attempt
		m.mu.Unlock()
		return true, nil
	}

	return false, err
}
