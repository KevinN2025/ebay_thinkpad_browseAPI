package main

import "strings"

var defaultExcludedTitleTerms = []string{
	"ultrabase",
	"charger",
	"adapter",
	"ac adapter",
	"power adapter",
	"power supply",
	"dock",
	"docking station",
	"port replicator",
}

func splitQueryTerms(query string) []string {
	return strings.Fields(strings.ToLower(strings.TrimSpace(query)))
}

func combineExcludedTerms(exclude string) []string {
	terms := make([]string, 0, len(defaultExcludedTitleTerms)+len(strings.Fields(exclude)))
	terms = append(terms, defaultExcludedTitleTerms...)
	terms = append(terms, splitQueryTerms(exclude)...)
	return terms
}

func buildBroadQuery(terms []string) string {
	if len(terms) <= 1 {
		return strings.Join(terms, " ")
	}
	return "(" + strings.Join(terms, ", ") + ")"
}

func filterItems(items []itemSummary, requiredTerms, excludedTerms []string) []itemSummary {
	filtered := make([]itemSummary, 0, len(items))
	for _, item := range items {
		if !titleContainsAllTerms(item.Title, requiredTerms) {
			continue
		}
		if titleContainsAnyTerm(item.Title, excludedTerms) {
			continue
		}
		filtered = append(filtered, item)
	}
	return filtered
}

func titleContainsAllTerms(title string, terms []string) bool {
	normalizedTitle := strings.ToLower(title)
	for _, term := range terms {
		if !strings.Contains(normalizedTitle, term) {
			return false
		}
	}
	return true
}

func titleContainsAnyTerm(title string, terms []string) bool {
	normalizedTitle := strings.ToLower(title)
	for _, term := range terms {
		if strings.Contains(normalizedTitle, term) {
			return true
		}
	}
	return false
}

func min(a, b int) int {
	if a < b {
		return a
	}
	return b
}
