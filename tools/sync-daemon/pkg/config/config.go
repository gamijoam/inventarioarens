package config

import (
	"encoding/json"
	"os"
	"strings"
)

// SyncConfig representa la estructura global de sync-config.json
type SyncConfig struct {
	Version int                     `json:"version"`
	Paused  bool                    `json:"paused"`
	Tenants map[string]TenantConfig `json:"tenants"`
}

// TenantConfig representa la configuración de sincronización de una empresa
type TenantConfig struct {
	Token            string `json:"token"`
	CloudURL         string `json:"cloud_url"`
	NodeCode         string `json:"node_code"`
	NodeName         string `json:"node_name"`
	InstallationCode string `json:"installation_code"`
	Limit            int    `json:"limit"`
	Interval         int    `json:"interval"`
}

// ApplyDefaults aplica los valores por defecto requeridos según la especificación de INVENTARIOARENS
func (t *TenantConfig) ApplyDefaults(slug string) {
	if strings.TrimSpace(t.NodeCode) == "" {
		t.NodeCode = "LOCAL-01"
	}
	if strings.TrimSpace(t.NodeName) == "" {
		t.NodeName = t.NodeCode
	}
	if strings.TrimSpace(t.InstallationCode) == "" {
		t.InstallationCode = t.NodeCode
	}
	if t.Interval <= 0 {
		t.Interval = 15
	}
	if t.Limit <= 0 {
		t.Limit = 50
	} else if t.Limit > 200 {
		t.Limit = 200
	}
}

// Load lee el archivo sync-config.json si existe. Si no existe, devuelve una estructura vacía sin error.
func Load(path string) (*SyncConfig, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return &SyncConfig{Tenants: make(map[string]TenantConfig)}, nil
		}
		return nil, err
	}

	var cfg SyncConfig
	if err := json.Unmarshal(data, &cfg); err != nil {
		return nil, err
	}

	if cfg.Tenants == nil {
		cfg.Tenants = make(map[string]TenantConfig)
	}

	return &cfg, nil
}

// ActiveTenants devuelve un mapa filtrado de empresas que tienen token y cloud_url válidos con defaults aplicados
func (c *SyncConfig) ActiveTenants() map[string]TenantConfig {
	active := make(map[string]TenantConfig)
	for slug, tc := range c.Tenants {
		trimmedToken := strings.TrimSpace(tc.Token)
		trimmedURL := strings.TrimSpace(tc.CloudURL)
		if trimmedToken == "" || trimmedURL == "" {
			continue
		}
		tc.ApplyDefaults(slug)
		active[slug] = tc
	}
	return active
}
