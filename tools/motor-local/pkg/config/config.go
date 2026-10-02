package config

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
)

// Config holds runtime configuration for MotorLocal across any company/tenant.
type Config struct {
	ProxyPort   int    `json:"proxy_port"`
	BackendPort int    `json:"backend_port"`
	DataDir     string `json:"data_dir"`
	PHPBinary   string `json:"php_binary"`
	BackendRoot string `json:"backend_root"`
	CloudURL    string `json:"cloud_url"`
	TenantSlug  string `json:"tenant_slug"`
	CompanyName string `json:"company_name"`
	OfflineMode bool   `json:"offline_mode"`
	LANIP       string `json:"lan_ip"`
}

// DefaultConfig returns clean, non-hardcoded defaults.
func DefaultConfig() *Config {
	return &Config{
		ProxyPort:   8787,
		BackendPort: 8788,
		CloudURL:    "https://app.balanzapro.com",
		TenantSlug:  "", // No company hardcoded by default
		CompanyName: "",
		OfflineMode: true,
		PHPBinary:   "php",
		BackendRoot: ".",
	}
}

// LoadConfig searches for config.json in the provided directories and loads it.
// If not found, it returns the default configuration.
func LoadConfig(searchDirs ...string) (*Config, error) {
	cfg := DefaultConfig()

	for _, dir := range searchDirs {
		if dir == "" {
			continue
		}
		candidate := filepath.Join(dir, "config.json")
		if data, err := os.ReadFile(candidate); err == nil {
			if err := json.Unmarshal(data, cfg); err != nil {
				return nil, fmt.Errorf("error parsing %s: %w", candidate, err)
			}
			return cfg, nil
		}
	}

	return cfg, nil
}

// SaveConfig serializes the configuration as formatted JSON to the target file path.
func SaveConfig(cfg *Config, targetPath string) error {
	dir := filepath.Dir(targetPath)
	if err := os.MkdirAll(dir, 0755); err != nil {
		return fmt.Errorf("error creating directory %s: %w", dir, err)
	}

	data, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		return fmt.Errorf("error encoding config json: %w", err)
	}

	if err := os.WriteFile(targetPath, data, 0644); err != nil {
		return fmt.Errorf("error writing config to %s: %w", targetPath, err)
	}

	return nil
}
