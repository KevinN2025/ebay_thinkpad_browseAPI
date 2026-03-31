package main

import (
	"os"
	"path/filepath"
	"reflect"
	"testing"
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

func TestFilterItemsByTitleContainsAll(t *testing.T) {
	items := []itemSummary{
		{Title: "IBM Lenovo ThinkPad X200"},
		{Title: "Lenovo ThinkPad T480"},
		{Title: "X200 battery for laptops"},
	}

	got := filterItemsByTitleContainsAll(items, []string{"thinkpad", "x200"})
	if len(got) != 1 {
		t.Fatalf("unexpected number of matches: %d", len(got))
	}
	if got[0].Title != "IBM Lenovo ThinkPad X200" {
		t.Fatalf("unexpected match: %q", got[0].Title)
	}
}
