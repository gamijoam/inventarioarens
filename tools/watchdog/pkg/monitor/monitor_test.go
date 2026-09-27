package monitor

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestCheckTarget_HealthyService(t *testing.T) {
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"ok":true,"service":"test"}`))
	}))
	defer ts.Close()

	m := NewMonitor()
	target := Target{
		Name:       "TestService",
		URL:        ts.URL,
		TimeoutSec: 2,
	}

	status := m.CheckTarget(target)
	if !status.Healthy {
		t.Errorf("Expected healthy=true, got false (err: %v)", status.Error)
	}

	if status.Latency <= 0 {
		t.Errorf("Expected positive latency measurement")
	}
}

func TestCheckTarget_UnhealthyService(t *testing.T) {
	ts := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer ts.Close()

	m := NewMonitor()
	target := Target{
		Name:       "BrokenService",
		URL:        ts.URL,
		TimeoutSec: 1,
	}

	status := m.CheckTarget(target)
	if status.Healthy {
		t.Errorf("Expected healthy=false for 500 status code")
	}
}

func TestMonitor_TracksConsecutiveFailures(t *testing.T) {
	m := NewMonitor()
	target := Target{
		Name:       "DownService",
		URL:        "http://127.0.0.1:59999/not-exist",
		TimeoutSec: 1,
	}

	// 1st failure
	s1 := m.CheckTarget(target)
	if s1.ConsecutiveFailures != 1 {
		t.Errorf("Expected 1 failure, got %d", s1.ConsecutiveFailures)
	}

	// 2nd failure
	s2 := m.CheckTarget(target)
	if s2.ConsecutiveFailures != 2 {
		t.Errorf("Expected 2 failures, got %d", s2.ConsecutiveFailures)
	}
}
