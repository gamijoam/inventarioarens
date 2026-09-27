package config_test

import (
	"os"
	"path/filepath"
	"testing"

	"inventarioarens/sync-daemon/pkg/config"
)

func TestLoadConfig_ValidFile(t *testing.T) {
	tempDir := t.TempDir()
	configPath := filepath.Join(tempDir, "sync-config.json")

	content := `{
		"version": 2,
		"paused": false,
		"tenants": {
			"asiamoto": {
				"token": "secret-token-123",
				"cloud_url": "https://app.balanzapro.com/api",
				"node_code": "LOCAL-01",
				"node_name": "Caja Principal",
				"installation_code": "INSTALL-01",
				"limit": 50,
				"interval": 15
			},
			"invalid-no-token": {
				"cloud_url": "https://app.balanzapro.com/api"
			},
			"invalid-no-url": {
				"token": "secret-token-456"
			}
		}
	}`

	if err := os.WriteFile(configPath, []byte(content), 0644); err != nil {
		t.Fatalf("failed to write test config file: %v", err)
	}

	cfg, err := config.Load(configPath)
	if err != nil {
		t.Fatalf("expected no error, got: %v", err)
	}

	if cfg.Paused {
		t.Errorf("expected paused to be false")
	}

	if cfg.Version != 2 {
		t.Errorf("expected version 2, got %d", cfg.Version)
	}

	active := cfg.ActiveTenants()
	if len(active) != 1 {
		t.Fatalf("expected exactly 1 active tenant, got %d", len(active))
	}

	asia, ok := active["asiamoto"]
	if !ok {
		t.Fatalf("expected tenant 'asiamoto' to be present")
	}

	if asia.Token != "secret-token-123" {
		t.Errorf("expected token 'secret-token-123', got '%s'", asia.Token)
	}
	if asia.CloudURL != "https://app.balanzapro.com/api" {
		t.Errorf("expected cloud_url 'https://app.balanzapro.com/api', got '%s'", asia.CloudURL)
	}
	if asia.NodeCode != "LOCAL-01" {
		t.Errorf("expected node_code 'LOCAL-01', got '%s'", asia.NodeCode)
	}
	if asia.Interval != 15 {
		t.Errorf("expected interval 15, got %d", asia.Interval)
	}
	if asia.Limit != 50 {
		t.Errorf("expected limit 50, got %d", asia.Limit)
	}
}

func TestLoadConfig_MissingFileReturnsEmpty(t *testing.T) {
	cfg, err := config.Load("/non/existent/path/sync-config.json")
	if err != nil {
		t.Fatalf("missing file should not error, got: %v", err)
	}
	if len(cfg.ActiveTenants()) != 0 {
		t.Errorf("expected 0 active tenants for missing file")
	}
}

func TestLoadConfig_PausedState(t *testing.T) {
	tempDir := t.TempDir()
	configPath := filepath.Join(tempDir, "sync-config.json")

	content := `{"paused": true, "tenants": {"t1": {"token": "abc", "cloud_url": "http://x"}}}`
	_ = os.WriteFile(configPath, []byte(content), 0644)

	cfg, err := config.Load(configPath)
	if err != nil {
		t.Fatalf("expected no error, got: %v", err)
	}
	if !cfg.Paused {
		t.Errorf("expected paused to be true")
	}
}

func TestTenantConfig_Defaults(t *testing.T) {
	tc := config.TenantConfig{
		Token:    "tok",
		CloudURL: "https://example.com/api",
	}
	tc.ApplyDefaults("my-slug")

	if tc.NodeCode != "LOCAL-01" {
		t.Errorf("expected default node_code 'LOCAL-01', got '%s'", tc.NodeCode)
	}
	if tc.NodeName != "LOCAL-01" {
		t.Errorf("expected default node_name 'LOCAL-01', got '%s'", tc.NodeName)
	}
	if tc.InstallationCode != "LOCAL-01" {
		t.Errorf("expected default installation_code 'LOCAL-01', got '%s'", tc.InstallationCode)
	}
	if tc.Interval != 15 {
		t.Errorf("expected default interval 15, got %d", tc.Interval)
	}
	if tc.Limit != 50 {
		t.Errorf("expected default limit 50, got %d", tc.Limit)
	}
}
