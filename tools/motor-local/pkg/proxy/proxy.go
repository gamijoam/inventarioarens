package proxy

import (
	"fmt"
	"net/http"
	"net/http/httputil"
	"net/url"
)

// NewAPIProxy creates a reverse proxy to targetURL that dynamically injects
// the tenantSlug in the X-Tenant header when not already specified by the client.
func NewAPIProxy(targetURL string, defaultTenantSlug string) (http.Handler, error) {
	parsedURL, err := url.Parse(targetURL)
	if err != nil {
		return nil, fmt.Errorf("invalid target URL: %w", err)
	}

	rp := httputil.NewSingleHostReverseProxy(parsedURL)
	originalDirector := rp.Director

	rp.Director = func(req *http.Request) {
		originalDirector(req)
		// Only inject if not already provided and we have a default configured tenant
		if req.Header.Get("X-Tenant") == "" && defaultTenantSlug != "" {
			req.Header.Set("X-Tenant", defaultTenantSlug)
		}
	}

	return rp, nil
}
