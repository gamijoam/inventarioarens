package index

import (
	"fmt"
	"sort"
	"strings"
	"sync"
)

type Product struct {
	ID          int     `json:"id"`
	TenantID    int     `json:"tenant_id"`
	SKU         string  `json:"sku"`
	Barcode     string  `json:"barcode"`
	Name        string  `json:"name"`
	Description string  `json:"description,omitempty"`
	Price       float64 `json:"price"`
	Stock       float64 `json:"stock"`
	Unit        string  `json:"unit,omitempty"`
}

type SearchResult struct {
	Product Product `json:"product"`
	Score   float64 `json:"score"`
}

type CatalogIndex struct {
	mu         sync.RWMutex
	products   map[string]Product // "tenant:id" -> Product
	byBarcode  map[string]Product // "tenant:barcode" -> Product
	bySKU      map[string]Product // "tenant:sku" -> Product
	tokens     map[string]map[int]struct{} // "tenant:token" -> set of product IDs
}

func NewCatalogIndex() *CatalogIndex {
	return &CatalogIndex{
		products:  make(map[string]Product),
		byBarcode: make(map[string]Product),
		bySKU:     make(map[string]Product),
		tokens:    make(map[string]map[int]struct{}),
	}
}

var accentReplacer = strings.NewReplacer(
	"á", "a", "é", "e", "í", "i", "ó", "o", "ú", "u",
	"Á", "a", "É", "e", "Í", "i", "Ó", "o", "Ú", "u",
	"ñ", "n", "Ñ", "n",
	"ü", "u", "Ü", "u",
)

func normalize(s string) string {
	replaced := accentReplacer.Replace(s)
	lower := strings.ToLower(replaced)
	// Replace non-alphanumeric with spaces for tokenization
	var b strings.Builder
	for _, r := range lower {
		if (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9') {
			b.WriteRune(r)
		} else {
			b.WriteRune(' ')
		}
	}
	return strings.TrimSpace(b.String())
}

func tokenize(s string) []string {
	norm := normalize(s)
	if norm == "" {
		return nil
	}
	fields := strings.Fields(norm)
	seen := make(map[string]struct{}, len(fields))
	var unique []string
	for _, f := range fields {
		if len(f) > 0 {
			if _, ok := seen[f]; !ok {
				seen[f] = struct{}{}
				unique = append(unique, f)
			}
		}
	}
	return unique
}

func (idx *CatalogIndex) AddOrUpdate(p Product) {
	idx.mu.Lock()
	defer idx.mu.Unlock()

	key := fmt.Sprintf("%d:%d", p.TenantID, p.ID)
	idx.products[key] = p

	if p.Barcode != "" {
		bKey := fmt.Sprintf("%d:%s", p.TenantID, strings.TrimSpace(p.Barcode))
		idx.byBarcode[bKey] = p
	}

	if p.SKU != "" {
		skuKey := fmt.Sprintf("%d:%s", p.TenantID, strings.ToUpper(strings.TrimSpace(p.SKU)))
		idx.bySKU[skuKey] = p
	}

	// Index tokens from name, sku and description
	text := fmt.Sprintf("%s %s %s", p.Name, p.SKU, p.Description)
	for _, tok := range tokenize(text) {
		tKey := fmt.Sprintf("%d:%s", p.TenantID, tok)
		if idx.tokens[tKey] == nil {
			idx.tokens[tKey] = make(map[int]struct{})
		}
		idx.tokens[tKey][p.ID] = struct{}{}
	}
}

func (idx *CatalogIndex) Count() int {
	idx.mu.RLock()
	defer idx.mu.RUnlock()
	return len(idx.products)
}

func (idx *CatalogIndex) GetByBarcode(barcode string, tenantID int) *Product {
	idx.mu.RLock()
	defer idx.mu.RUnlock()

	bKey := fmt.Sprintf("%d:%s", tenantID, strings.TrimSpace(barcode))
	if p, ok := idx.byBarcode[bKey]; ok {
		return &p
	}
	return nil
}

func (idx *CatalogIndex) Search(query string, tenantID int, limit int) []SearchResult {
	idx.mu.RLock()
	defer idx.mu.RUnlock()

	trimmed := strings.TrimSpace(query)
	if trimmed == "" {
		return nil
	}

	if limit <= 0 {
		limit = 20
	}

	// 1. Direct barcode match
	bKey := fmt.Sprintf("%d:%s", tenantID, trimmed)
	if p, ok := idx.byBarcode[bKey]; ok {
		return []SearchResult{{Product: p, Score: 100.0}}
	}

	// 2. Direct SKU match
	skuKey := fmt.Sprintf("%d:%s", tenantID, strings.ToUpper(trimmed))
	if p, ok := idx.bySKU[skuKey]; ok {
		return []SearchResult{{Product: p, Score: 95.0}}
	}

	// 3. Token-based matching & scoring
	qTokens := tokenize(trimmed)
	if len(qTokens) == 0 {
		return nil
	}

	scores := make(map[int]float64)

	tenantPrefix := fmt.Sprintf("%d:", tenantID)
	for idxKey, idSet := range idx.tokens {
		if !strings.HasPrefix(idxKey, tenantPrefix) {
			continue
		}
		tok := strings.TrimPrefix(idxKey, tenantPrefix)

		for _, qTok := range qTokens {
			var matchScore float64
			if tok == qTok {
				matchScore = 10.0 // exact word
			} else if strings.HasPrefix(tok, qTok) {
				matchScore = 5.0 // prefix match
			} else if strings.Contains(tok, qTok) {
				matchScore = 2.0 // substring match
			}

			if matchScore > 0 {
				for id := range idSet {
					scores[id] += matchScore
				}
			}
		}
	}

	if len(scores) == 0 {
		return nil
	}

	// Collect and sort results
	results := make([]SearchResult, 0, len(scores))
	for id, score := range scores {
		pKey := fmt.Sprintf("%d:%d", tenantID, id)
		if p, ok := idx.products[pKey]; ok {
			results = append(results, SearchResult{Product: p, Score: score})
		}
	}

	sort.Slice(results, func(i, j int) bool {
		return results[i].Score > results[j].Score
	})

	if len(results) > limit {
		results = results[:limit]
	}

	return results
}
