package main

import (
	"context"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"
)

func TestLoadDotEnvFileLegacyFormat(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, ".env")

	content := "App ID (Client ID)\nclient-id\nDev ID\ndev-id\nCert ID (Client Secret)\nclient-secret\n"
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}

	cfg, err := loadDotEnvFile(path)
	if err != nil {
		t.Fatal(err)
	}

	if cfg.ClientID != "client-id" {
		t.Fatalf("unexpected client id: %q", cfg.ClientID)
	}
	if cfg.ClientSecret != "client-secret" {
		t.Fatalf("unexpected client secret: %q", cfg.ClientSecret)
	}
	if cfg.Environment != "sandbox" {
		t.Fatalf("unexpected environment: %q", cfg.Environment)
	}
	if cfg.MarketplaceID != "EBAY_US" {
		t.Fatalf("unexpected marketplace: %q", cfg.MarketplaceID)
	}
}

func TestLoadDotEnvFileKeyValueFormat(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, ".env")

	content := "EBAY_CLIENT_ID=client-id\nEBAY_CLIENT_SECRET=client-secret\nEBAY_ENV=production\nEBAY_MARKETPLACE_ID=EBAY_GB\n"
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}

	cfg, err := loadDotEnvFile(path)
	if err != nil {
		t.Fatal(err)
	}

	if cfg.ClientID != "client-id" {
		t.Fatalf("unexpected client id: %q", cfg.ClientID)
	}
	if cfg.ClientSecret != "client-secret" {
		t.Fatalf("unexpected client secret: %q", cfg.ClientSecret)
	}
	if cfg.Environment != "production" {
		t.Fatalf("unexpected environment: %q", cfg.Environment)
	}
	if cfg.MarketplaceID != "EBAY_GB" {
		t.Fatalf("unexpected marketplace: %q", cfg.MarketplaceID)
	}
}

func TestLoadDotEnvFileAccessTokenFormat(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, ".env")

	content := "EBAY_ACCESS_TOKEN=test-token\nEBAY_ENV=production\nEBAY_MARKETPLACE_ID=EBAY_US\n"
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}

	cfg, err := loadDotEnvFile(path)
	if err != nil {
		t.Fatal(err)
	}

	if cfg.AccessToken != "test-token" {
		t.Fatalf("unexpected access token: %q", cfg.AccessToken)
	}
	if cfg.Environment != "production" {
		t.Fatalf("unexpected environment: %q", cfg.Environment)
	}
	if cfg.MarketplaceID != "EBAY_US" {
		t.Fatalf("unexpected marketplace: %q", cfg.MarketplaceID)
	}
}

func TestSplitQueryTerms(t *testing.T) {
	got := splitQueryTerms("  Thinkpad   x200  ")
	want := []string{"thinkpad", "x200"}

	if !reflect.DeepEqual(got, want) {
		t.Fatalf("unexpected terms: got %v want %v", got, want)
	}
}

func TestBuildBroadQuery(t *testing.T) {
	if got := buildBroadQuery([]string{"thinkpad", "x200"}); got != "(thinkpad, x200)" {
		t.Fatalf("unexpected broad query: %q", got)
	}
}

func TestTitleContainsAllTerms(t *testing.T) {
	if !titleContainsAllTerms("IBM Lenovo ThinkPad X200 Tablet", []string{"thinkpad", "x200"}) {
		t.Fatal("expected title to match all query terms")
	}

	if titleContainsAllTerms("Lenovo ThinkPad T480", []string{"thinkpad", "x200"}) {
		t.Fatal("expected title without x200 to be rejected")
	}
}

func TestTitleContainsAnyTerm(t *testing.T) {
	if !titleContainsAnyTerm("Lenovo ThinkPad X200 charger", []string{"charger", "adapter"}) {
		t.Fatal("expected title to match an excluded term")
	}

	if titleContainsAnyTerm("Lenovo ThinkPad X200", []string{"charger", "adapter"}) {
		t.Fatal("expected title without excluded terms to pass")
	}
}

func TestFilterItems(t *testing.T) {
	items := []itemSummary{
		{Title: "IBM Lenovo ThinkPad X200"},
		{Title: "IBM Lenovo ThinkPad X200 Charger"},
		{Title: "IBM Lenovo ThinkPad X200 AC Adapter"},
		{Title: "Lenovo ThinkPad T480"},
		{Title: "X200 battery for laptops"},
	}

	got := filterItems(items, []string{"thinkpad", "x200"}, []string{"charger", "adapter"})
	if len(got) != 1 {
		t.Fatalf("unexpected number of matches: %d", len(got))
	}
	if got[0].Title != "IBM Lenovo ThinkPad X200" {
		t.Fatalf("unexpected match: %q", got[0].Title)
	}
}

func TestCombineExcludedTermsAddsDefaultAccessoryFilters(t *testing.T) {
	got := combineExcludedTerms("battery cracked")

	for _, term := range []string{"ultrabase", "charger", "ac adapter", "battery", "cracked"} {
		if !titleContainsAnyTerm(strings.Join(got, " "), []string{term}) {
			t.Fatalf("expected excluded terms to contain %q, got %v", term, got)
		}
	}
}

func TestFilterItemsDefaultAccessoryTerms(t *testing.T) {
	items := []itemSummary{
		{Title: "IBM Lenovo ThinkPad X200"},
		{Title: "IBM Lenovo ThinkPad X200 Ultrabase"},
		{Title: "IBM Lenovo ThinkPad X200 Docking Station"},
		{Title: "IBM Lenovo ThinkPad X200 Power Adapter"},
	}

	got := filterItems(items, []string{"thinkpad", "x200"}, combineExcludedTerms(""))
	if len(got) != 1 {
		t.Fatalf("unexpected number of matches: %d", len(got))
	}
	if got[0].Title != "IBM Lenovo ThinkPad X200" {
		t.Fatalf("unexpected match: %q", got[0].Title)
	}
}

func TestAuthSessionReturnsStaticTokenWithoutCredentials(t *testing.T) {
	session := newAuthSession(config{AccessToken: "static-token"}, func(context.Context) (tokenResponse, error) {
		t.Fatal("fetcher should not be called without client credentials")
		return tokenResponse{}, nil
	})

	got, err := session.token(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if got != "static-token" {
		t.Fatalf("unexpected token: %q", got)
	}
}

func TestAuthSessionRefreshesAndCachesToken(t *testing.T) {
	calls := 0
	session := newAuthSession(config{
		ClientID:     "client-id",
		ClientSecret: "client-secret",
	}, func(context.Context) (tokenResponse, error) {
		calls++
		return tokenResponse{AccessToken: "fresh-token", ExpiresIn: 7200}, nil
	})

	first, err := session.token(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	second, err := session.token(context.Background())
	if err != nil {
		t.Fatal(err)
	}

	if first != "fresh-token" || second != "fresh-token" {
		t.Fatalf("unexpected tokens: %q %q", first, second)
	}
	if calls != 1 {
		t.Fatalf("expected one refresh call, got %d", calls)
	}
}

func TestTokenExpiryDeadlineImmediateForShortExpiry(t *testing.T) {
	deadline := tokenExpiryDeadline(120)
	if time.Until(deadline) > time.Second {
		t.Fatalf("expected immediate refresh deadline, got %s", time.Until(deadline))
	}
}
