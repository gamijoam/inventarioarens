package config

import (
	"os"
	"path/filepath"
	"testing"
)

func TestLoadConfig_Defaults(t *testing.T) {
	// Create empty temp directory with no config.json
	tempDir := t.TempDir()

	cfg, err := LoadConfig(tempDir)
	if err != nil {
		t.Fatalf("unexpected error loading config: %v", err)
	}

	if cfg.ProxyPort != 8787 {
		t.Errorf("expected default ProxyPort 8787, got %d", cfg.ProxyPort)
	}
	if cfg.BackendPort != 8788 {
		t.Errorf("expected default BackendPort 8788, got %d", cfg.BackendPort)
	}
	if cfg.CloudURL != "https://app.balanzapro.com" {
		t.Errorf("expected default CloudURL, got %s", cfg.CloudURL)
	}
	// TenantSlug should be empty if not configured (NOT hardcoded to any company!)
	if cfg.TenantSlug != "" {
		t.Errorf("expected empty TenantSlug by default (not hardcoded), got %q", cfg.TenantSlug)
	}
}

func TestLoadConfig_FromJSON(t *testing.T) {
	tempDir := t.TempDir()
	jsonContent := `{
		"proxy_port": 9000,
		"backend_port": 9001,
		"cloud_url": "https://app.tiendasarens.com",
		"tenant_slug": "arens-centro",
		"company_name": "Tiendas Arens Centro",
		"offline_mode": true
	}`
	configFile := filepath.Join(tempDir, "config.json")
	if err := os.WriteFile(configFile, []byte(jsonContent), 0644); err != nil {
		t.Fatalf("failed to write test config.json: %v", err)
	}

	cfg, err := LoadConfig(tempDir)
	if err != nil {
		t.Fatalf("failed to load config from json: %v", err)
	}

	if cfg.ProxyPort != 9000 {
		t.Errorf("expected ProxyPort 9000, got %d", cfg.ProxyPort)
	}
	if cfg.BackendPort != 9001 {
		t.Errorf("expected BackendPort 9001, got %d", cfg.BackendPort)
	}
	if cfg.CloudURL != "https://app.tiendasarens.com" {
		t.Errorf("expected custom CloudURL, got %s", cfg.CloudURL)
	}
	if cfg.TenantSlug != "arens-centro" {
		t.Errorf("expected TenantSlug 'arens-centro', got %s", cfg.TenantSlug)
	}
	if cfg.CompanyName != "Tiendas Arens Centro" {
		t.Errorf("expected CompanyName 'Tiendas Arens Centro', got %s", cfg.CompanyName)
	}
}

func TestSaveConfig(t *testing.T) {
	tempDir := t.TempDir()
	configFile := filepath.Join(tempDir, "config.json")

	cfg := &Config{
		ProxyPort:   8080,
		BackendPort: 8081,
		CloudURL:    "https://app.repuestosavilacar.com",
		TenantSlug:  "avilacar-principal",
		CompanyName: "Repuestos Avilacar C.A.",
		OfflineMode: true,
	}

	if err := SaveConfig(cfg, configFile); err != nil {
		t.Fatalf("failed to save config: %v", err)
	}

	// Verify we can read it back
	loaded, err := LoadConfig(tempDir)
	if err != nil {
		t.Fatalf("failed to reload saved config: %v", err)
	}

	if loaded.TenantSlug != "avilacar-principal" {
		t.Errorf("expected reloaded TenantSlug 'avilacar-principal', got %s", loaded.TenantSlug)
	}
	if loaded.CompanyName != "Repuestos Avilacar C.A." {
		t.Errorf("expected reloaded CompanyName 'Repuestos Avilacar C.A.', got %s", loaded.CompanyName)
	}
}
